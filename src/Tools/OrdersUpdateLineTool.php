<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\OrderService;

/**
 * Spec 17/S2+S3 (write): eine Bestellzeile im OFFENEN Entwurf pflegen — Gebinde-Anzahl
 * manuell übersteuern (qty_packs), Auto-Menge wiederherstellen (reset_qty) oder Zeile
 * entfernen (remove). Nur eigene Team-Belege, nur solange draft (Guard im Service).
 */
class OrdersUpdateLineTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.orders.UPDATE_LINE';
    }

    public function getDescription(): string
    {
        return 'Pflegt eine Bestellzeile im offenen Entwurf: qty_packs (Gebinde-Anzahl manuell setzen — '
            . 'bleibt bei Recompute stehen), reset_qty=true (Auto-Menge wiederherstellen) oder remove=true '
            . '(Zeile entfernen). Nur eigene Belege, nur im draft.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'line_id' => ['type' => 'integer'],
                'qty_packs' => ['type' => 'number', 'description' => 'Gebinde-Anzahl manuell (übersteuert Auto-Rundung)'],
                'reset_qty' => ['type' => 'boolean', 'description' => 'true = zurück auf Auto-Menge'],
                'remove' => ['type' => 'boolean', 'description' => 'true = Zeile entfernen'],
            ],
            'required' => ['line_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(OrderService::class);
        $lineId = (int) $arguments['line_id'];

        try {
            if (! empty($arguments['remove'])) {
                $svc->removeLine($team, $lineId);

                return ToolResult::success(['line_id' => $lineId, 'removed' => true]);
            }
            $input = [];
            if (array_key_exists('qty_packs', $arguments)) {
                $input['qty_packs'] = $arguments['qty_packs'];
            }
            if (! empty($arguments['reset_qty'])) {
                $input['reset_qty'] = true;
            }
            if ($input === []) {
                return ToolResult::error('Nichts zu ändern (qty_packs/reset_qty/remove angeben).', 'NO_CHANGE');
            }
            $line = $svc->updateLine($team, $lineId, $input);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'NOT_ALLOWED');
        } catch (\Throwable $e) {
            return ToolResult::error('Bestellzeile nicht im Zugriff.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'line_id' => (int) $line->id,
            'qty_packs' => (float) $line->qty_packs,
            'is_manual_qty' => (bool) $line->is_manual_qty,
            'line_total' => (float) $line->line_total,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'tags' => ['foodalchemist', 'bestellung', 'order', 'zeile', 'gebinde'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'low',
        ];
    }
}
