<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryMovement;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryStock;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;

/**
 * WaWi light: idempotenter Lagerzugang aus Wareneingangszeilen.
 */
class InventoryService
{
    public function syncReceiptLine(FoodAlchemistOrderLine $line): ?FoodAlchemistInventoryStock
    {
        $line->loadMissing('order');
        $order = $line->order;
        if ($order === null || $order->team_id === null) {
            return null;
        }

        return DB::transaction(function () use ($line, $order) {
            $freshLine = FoodAlchemistOrderLine::with('order')->lockForUpdate()->find($line->id);
            if ($freshLine === null || $freshLine->order === null) {
                return null;
            }

            [$targetQty, $baseUnit] = $this->receivedBaseQuantity($freshLine);
            $movement = FoodAlchemistInventoryMovement::where('source_hash', $this->receiptSourceHash($freshLine))
                ->lockForUpdate()
                ->first();
            $oldQty = $movement !== null ? (float) $movement->qty_base : 0.0;
            $delta = round($targetQty - $oldQty, 4);

            $stock = $this->stockForLine($freshLine, $baseUnit, true);
            if ($stock === null) {
                return null;
            }

            if (abs($delta) >= 0.0001) {
                $stock->qty_base = round((float) $stock->qty_base + $delta, 4);
                $stock->save();
            }

            $payload = [
                'team_id' => (int) $freshLine->order->team_id,
                'stock_id' => (int) $stock->id,
                'inventory_location_id' => $stock->inventory_location_id !== null ? (int) $stock->inventory_location_id : null,
                'gp_id' => $freshLine->gp_id !== null ? (int) $freshLine->gp_id : null,
                'supplier_item_id' => $freshLine->supplier_item_id !== null ? (int) $freshLine->supplier_item_id : null,
                'order_id' => (int) $freshLine->order_id,
                'order_line_id' => (int) $freshLine->id,
                'direction' => 'in',
                'qty_base' => $targetQty,
                'base_unit' => $baseUnit,
                'qty_packs' => $freshLine->received_qty_packs !== null ? round((float) $freshLine->received_qty_packs, 2) : null,
                'source' => 'wareneingang',
                'moved_at' => $freshLine->received_at,
                'note' => $freshLine->received_note,
            ];

            if ($movement === null) {
                FoodAlchemistInventoryMovement::create($payload + [
                    'source_hash' => $this->receiptSourceHash($freshLine),
                ]);
            } else {
                $movement->fill($payload);
                $movement->save();
            }

            return $stock->refresh();
        });
    }

    /** @return array{qty_base:float, base_unit:string, display:string, shortage_base:float, shortage_display:string}|null */
    public function lineStockSummary(Team $team, FoodAlchemistOrderLine $line): ?array
    {
        [$unit] = $this->baseUnitForLine($line);
        $query = FoodAlchemistInventoryStock::where('team_id', (int) $team->id)
            ->where('base_unit', $unit)
            ->where(fn ($q) => $q->whereNull('inventory_location_id')
                ->orWhereHas('location', fn ($loc) => $loc->where('is_active', true)));

        if ($line->gp_id !== null) {
            $query->where('gp_id', (int) $line->gp_id)->whereNull('supplier_item_id');
        } elseif ($line->supplier_item_id !== null) {
            $query->whereNull('gp_id')->where('supplier_item_id', (int) $line->supplier_item_id);
        } else {
            return null;
        }

        if (! $query->exists()) {
            return null;
        }

        $qty = (float) $query->sum('qty_base');
        $needed = $this->lineNeedInBaseUnit($line, $unit);
        $shortage = max(0.0, round($needed - $qty, 4));

        return [
            'qty_base' => round($qty, 4),
            'base_unit' => $unit,
            'display' => $this->displayQuantity($qty, $unit),
            'shortage_base' => $shortage,
            'shortage_display' => $this->displayQuantity($shortage, $unit),
        ];
    }

    /** @return array{0:float, 1:string} */
    private function receivedBaseQuantity(FoodAlchemistOrderLine $line): array
    {
        $packs = $line->received_qty_packs !== null ? max(0.0, (float) $line->received_qty_packs) : 0.0;
        [$baseUnit, $packBaseQty] = $this->baseUnitForLine($line);

        if ($packs <= 0.0) {
            return [0.0, $baseUnit];
        }

        $orderedPacks = (float) $line->qty_packs;
        $neededBase = (float) $line->needed_base_g;
        if ($orderedPacks > 0.0 && $neededBase > 0.0) {
            return [round($neededBase * ($packs / $orderedPacks), 4), $baseUnit];
        }

        return [round($packs * $packBaseQty, 4), $baseUnit];
    }

    /** @return array{0:string, 1:float} */
    private function baseUnitForLine(FoodAlchemistOrderLine $line): array
    {
        $unit = strtolower(trim((string) $line->unit_code));
        $packQty = max(0.0, (float) $line->pack_qty);

        return match ($unit) {
            'kg' => ['g', $packQty * 1000.0],
            'g' => ['g', $packQty],
            'l' => ['ml', $packQty * 1000.0],
            'ml' => ['ml', $packQty],
            'stk', 'stück', 'stueck' => ['Stk', $packQty],
            default => ['g', $packQty * 1000.0],
        };
    }

    private function stockForLine(FoodAlchemistOrderLine $line, string $baseUnit, bool $create): ?FoodAlchemistInventoryStock
    {
        $line->loadMissing('order');
        if ($line->order === null || ($line->gp_id === null && $line->supplier_item_id === null)) {
            return null;
        }

        $query = FoodAlchemistInventoryStock::where('team_id', (int) $line->order->team_id)
            ->where('base_unit', $baseUnit);
        $location = $this->defaultLocationForTeam((int) $line->order->team_id, $create);
        if ($location !== null) {
            $query->where('inventory_location_id', (int) $location->id);
        } else {
            $query->whereNull('inventory_location_id');
        }

        if ($line->gp_id !== null) {
            $query->where('gp_id', (int) $line->gp_id)->whereNull('supplier_item_id');
        } else {
            $query->whereNull('gp_id')->where('supplier_item_id', (int) $line->supplier_item_id);
        }

        $stock = $query->lockForUpdate()->first();
        if ($stock !== null || ! $create) {
            return $stock;
        }

        return FoodAlchemistInventoryStock::create([
            'team_id' => (int) $line->order->team_id,
            'inventory_location_id' => $location !== null ? (int) $location->id : null,
            'gp_id' => $line->gp_id !== null ? (int) $line->gp_id : null,
            'supplier_item_id' => $line->gp_id === null && $line->supplier_item_id !== null ? (int) $line->supplier_item_id : null,
            'qty_base' => 0,
            'base_unit' => $baseUnit,
        ]);
    }

    private function defaultLocationForTeam(int $teamId, bool $create): ?FoodAlchemistInventoryLocation
    {
        $location = FoodAlchemistInventoryLocation::where('team_id', $teamId)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->lockForUpdate()
            ->first();
        if ($location !== null || ! $create) {
            return $location;
        }

        return FoodAlchemistInventoryLocation::create([
            'team_id' => $teamId,
            'name' => 'Hauptlager',
            'code' => 'MAIN',
            'type' => 'warehouse',
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    private function receiptSourceHash(FoodAlchemistOrderLine $line): string
    {
        return sha1('fa_order_receipt:' . (int) $line->order_id . ':line:' . (int) $line->id);
    }

    private function lineNeedInBaseUnit(FoodAlchemistOrderLine $line, string $unit): float
    {
        if (in_array($unit, ['g', 'ml'], true) && (float) $line->needed_base_g > 0.0) {
            return round((float) $line->needed_base_g, 4);
        }

        return round((float) $line->qty_packs * max(0.0, (float) $line->pack_qty), 4);
    }

    private function displayQuantity(float $qty, string $unit): string
    {
        if ($unit === 'g' && abs($qty) >= 1000.0) {
            return rtrim(rtrim(number_format($qty / 1000.0, 3, ',', '.'), '0'), ',') . ' kg';
        }
        if ($unit === 'ml' && abs($qty) >= 1000.0) {
            return rtrim(rtrim(number_format($qty / 1000.0, 3, ',', '.'), '0'), ',') . ' l';
        }

        return rtrim(rtrim(number_format($qty, 3, ',', '.'), '0'), ',') . ' ' . $unit;
    }
}
