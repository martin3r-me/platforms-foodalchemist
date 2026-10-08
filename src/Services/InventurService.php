<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryCount;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryCountLine;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryLocation;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryMovement;
use Platform\FoodAlchemist\Models\FoodAlchemistInventoryStock;
use Platform\FoodAlchemist\Models\FoodAlchemistPurchaseTransaction;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * Spec 66 · Inventur (Stufe 1, periodisches Lager): Stichtag + Lagerort + Zählliste.
 *
 * - Anlegen friert je Position den Soll-Bestand und den Bewertungspreis (€ je g/ml/Stk) ein.
 * - Buchen setzt den Bestand auf die gezählte Menge; die Differenz wird als Bewegung
 *   `source = inventur` gebucht (idempotent über source_hash). Nicht gezählte Positionen
 *   bleiben unberührt.
 * - `bestandswert()` liefert den bewerteten Bestand zum Stichtag (je Lagerort die jüngste
 *   gebuchte Inventur ≤ Stichtag) — Grundlage für Anfangs-/Endbestand im Controlling.
 *
 * Team-strikt (`where team_id`), wie Bestand und Einkaufsjournal: ein Kind-Team sieht nicht
 * den Bestand der Zentrale.
 */
class InventurService
{
    /** Wie weit zurück eingekaufte Grundprodukte die Zählliste vorbelegen. */
    public const VORBELEGUNG_TAGE = 90;

    public function __construct(
        private RecipeRecomputeService $recompute,
        private InventoryService $inventory,
    ) {}

    /** @return \Illuminate\Support\Collection<int, FoodAlchemistInventoryCount> */
    public function liste(Team $team)
    {
        return FoodAlchemistInventoryCount::where('team_id', $team->id)->with('location')
            ->withCount('lines')->orderByDesc('count_date')->orderByDesc('id')->get();
    }

    public function detail(Team $team, int $countId): FoodAlchemistInventoryCount
    {
        return FoodAlchemistInventoryCount::where('team_id', $team->id)
            ->with(['location', 'lines.gp:id,name,piece_default_g', 'lines.supplierItem:id,designation,article_number'])
            ->findOrFail($countId);
    }

    /** Neue Inventur für einen Lagerort, Zählliste vorbelegt (Bestand + Einkäufe der letzten 90 Tage). */
    public function anlegen(Team $team, int $locationId, string $datum, ?string $notiz = null): FoodAlchemistInventoryCount
    {
        $location = FoodAlchemistInventoryLocation::where('team_id', $team->id)->findOrFail($locationId);
        $stichtag = Carbon::parse($datum)->toDateString();

        return DB::transaction(function () use ($team, $location, $stichtag, $notiz) {
            $count = FoodAlchemistInventoryCount::create([
                'team_id' => $team->id, 'inventory_location_id' => $location->id,
                'count_date' => $stichtag, 'status' => 'offen', 'note' => $notiz,
            ]);

            $positionen = [];
            // 1. Alles, was am Lagerort Bestand führt (auch 0 — es war schon einmal da).
            foreach (FoodAlchemistInventoryStock::where('team_id', $team->id)->where('inventory_location_id', $location->id)->get() as $s) {
                $key = $this->schluessel($s->gp_id, $s->supplier_item_id, $s->base_unit);
                $positionen[$key] = ['gp_id' => $s->gp_id, 'supplier_item_id' => $s->gp_id === null ? $s->supplier_item_id : null,
                    'base_unit' => $s->base_unit, 'qty_expected' => (float) $s->qty_base];
            }
            // 2. Grundprodukte, die zuletzt eingekauft, aber noch nicht im Bestand sind (nur am Standardlager).
            if ($location->is_default) {
                $seit = Carbon::parse($stichtag)->subDays(self::VORBELEGUNG_TAGE)->toDateString();
                $einkauf = FoodAlchemistPurchaseTransaction::where('team_id', $team->id)->whereNotNull('gp_id')
                    ->whereDate('purchased_at', '>=', $seit)->whereDate('purchased_at', '<=', $stichtag)
                    ->select('gp_id', 'unit_code')->distinct()->get();
                foreach ($einkauf as $t) {
                    $unit = $this->basisEinheit((string) $t->unit_code);
                    if ($unit === '') {
                        continue;
                    }
                    $positionen[$this->schluessel($t->gp_id, null, $unit)] ??= ['gp_id' => (int) $t->gp_id, 'supplier_item_id' => null, 'base_unit' => $unit, 'qty_expected' => 0.0];
                }
            }

            $this->positionenAnlegen($team, $count, array_values($positionen));

            return $count->refresh();
        });
    }

    /** Position (Grundprodukt) nachtragen. Basiseinheit aus dem Lead-Artikel, sonst g. */
    public function positionHinzu(Team $team, int $countId, int $gpId, ?string $baseUnit = null): FoodAlchemistInventoryCountLine
    {
        $count = $this->offen($team, $countId);
        $gp = FoodAlchemistGp::visibleToTeam($team)->findOrFail($gpId);
        $unit = $baseUnit !== null && in_array($baseUnit, ['g', 'ml', 'Stk'], true)
            ? $baseUnit
            : ($this->basisEinheit((string) ($gp->leadLa?->unit_code ?? 'kg')) ?: 'g');
        $vorhanden = $count->lines()->where('gp_id', $gp->id)->where('base_unit', $unit)->first();
        if ($vorhanden !== null) {
            return $vorhanden;
        }
        $soll = (float) FoodAlchemistInventoryStock::where('team_id', $team->id)->where('inventory_location_id', $count->inventory_location_id)
            ->where('gp_id', $gp->id)->whereNull('supplier_item_id')->where('base_unit', $unit)->sum('qty_base');
        $this->positionenAnlegen($team, $count, [['gp_id' => $gp->id, 'supplier_item_id' => null, 'base_unit' => $unit, 'qty_expected' => $soll]]);

        return $count->lines()->where('gp_id', $gp->id)->where('base_unit', $unit)->firstOrFail();
    }

    /**
     * Gezählte Menge setzen. Eingabe in der Anzeige-Einheit (kg / l / Stk), gespeichert in der
     * Basiseinheit (g / ml / Stk). Leer = nicht gezählt.
     */
    public function zaehlen(Team $team, int $lineId, mixed $menge): FoodAlchemistInventoryCountLine
    {
        $line = FoodAlchemistInventoryCountLine::where('team_id', $team->id)->findOrFail($lineId);
        $this->offen($team, (int) $line->inventory_count_id);
        $roh = trim(str_replace(',', '.', (string) ($menge ?? '')));
        if ($roh === '') {
            $line->update(['qty_counted' => null]);

            return $line->refresh();
        }
        if (! is_numeric($roh) || (float) $roh < 0) {
            throw new \RuntimeException('Gezählte Menge braucht eine Zahl ≥ 0.');
        }
        $faktor = in_array($line->base_unit, ['g', 'ml'], true) ? 1000.0 : 1.0;
        $line->update(['qty_counted' => round((float) $roh * $faktor, 4)]);

        return $line->refresh();
    }

    /** Offene Inventur buchen: Bestand := gezählt, Differenz als Bewegung. */
    public function buchen(Team $team, int $countId, ?int $userId = null): FoodAlchemistInventoryCount
    {
        $count = $this->offen($team, $countId);

        return DB::transaction(function () use ($team, $count, $userId) {
            $gebuchtAm = Carbon::parse($count->count_date)->endOfDay();
            $wert = 0.0;
            foreach ($count->lines()->get() as $line) {
                if ($line->qty_counted === null) {
                    continue;   // nicht gezählt — Bestand bleibt
                }
                $stock = FoodAlchemistInventoryStock::where('team_id', $team->id)
                    ->where('inventory_location_id', $count->inventory_location_id)->where('base_unit', $line->base_unit)
                    ->when($line->gp_id !== null,
                        fn ($q) => $q->where('gp_id', $line->gp_id)->whereNull('supplier_item_id'),
                        fn ($q) => $q->whereNull('gp_id')->where('supplier_item_id', $line->supplier_item_id))
                    ->lockForUpdate()->first()
                    ?? FoodAlchemistInventoryStock::create([
                        'team_id' => $team->id, 'inventory_location_id' => $count->inventory_location_id,
                        'gp_id' => $line->gp_id, 'supplier_item_id' => $line->gp_id === null ? $line->supplier_item_id : null,
                        'qty_base' => 0, 'base_unit' => $line->base_unit,
                    ]);
                $differenz = round((float) $line->qty_counted - (float) $stock->qty_base, 4);
                if (abs($differenz) >= 0.0001) {
                    FoodAlchemistInventoryMovement::updateOrCreate(
                        ['source_hash' => sha1('fa_inventur:' . $count->id . ':line:' . $line->id)],
                        [
                            'team_id' => $team->id, 'stock_id' => $stock->id, 'inventory_location_id' => $count->inventory_location_id,
                            'gp_id' => $line->gp_id, 'supplier_item_id' => $line->supplier_item_id,
                            'direction' => $differenz > 0 ? 'in' : 'out', 'qty_base' => abs($differenz), 'base_unit' => $line->base_unit,
                            'source' => 'inventur', 'moved_at' => $gebuchtAm,
                            'note' => 'Inventur ' . Carbon::parse($count->count_date)->format('d.m.Y'),
                        ],
                    );
                    $stock->update(['qty_base' => round((float) $line->qty_counted, 4)]);
                }
                $wert += (float) ($line->wert() ?? 0);
            }
            $count->update(['status' => 'gebucht', 'booked_at' => now(), 'booked_by' => $userId, 'value_total' => round($wert, 2)]);

            return $count->refresh();
        });
    }

    /** Offene Inventur verwerfen (gebuchte bleiben als Beleg). */
    public function loeschen(Team $team, int $countId): void
    {
        $count = $this->offen($team, $countId);
        DB::transaction(function () use ($count) {
            $count->lines()->delete();
            $count->delete();
        });
    }

    /**
     * Bewerteter Bestand zum Stichtag: je aktivem Lagerort die jüngste gebuchte Inventur mit
     * count_date ≤ Stichtag. `vollstaendig` = jeder aktive Lagerort hat eine solche Inventur.
     *
     * @return array{wert: float, vollstaendig: bool, inventuren: list<array{id:int, lagerort:string, datum:string, wert:float}>}
     */
    public function bestandswert(Team $team, string $stichtag): array
    {
        $orte = FoodAlchemistInventoryLocation::where('team_id', $team->id)->where('is_active', true)->get();
        $treffer = [];
        $wert = 0.0;
        foreach ($orte as $ort) {
            $c = FoodAlchemistInventoryCount::where('team_id', $team->id)->where('inventory_location_id', $ort->id)
                ->where('status', 'gebucht')->whereDate('count_date', '<=', $stichtag)
                ->orderByDesc('count_date')->orderByDesc('id')->first();
            if ($c === null) {
                continue;
            }
            $treffer[] = ['id' => (int) $c->id, 'lagerort' => (string) $ort->name, 'datum' => $c->count_date->toDateString(), 'wert' => (float) $c->value_total];
            $wert += (float) $c->value_total;
        }

        return ['wert' => round($wert, 2), 'vollstaendig' => $orte->isNotEmpty() && count($treffer) === $orte->count(), 'inventuren' => $treffer];
    }

    /** Summen einer Inventur für Kopf/Druck. */
    public function summen(FoodAlchemistInventoryCount $count): array
    {
        $lines = $count->relationLoaded('lines') ? $count->lines : $count->lines()->get();
        $gezaehlt = $lines->whereNotNull('qty_counted');

        return [
            'positionen' => $lines->count(),
            'gezaehlt' => $gezaehlt->count(),
            'offen' => $lines->count() - $gezaehlt->count(),
            'wert' => round($gezaehlt->sum(fn ($l) => (float) ($l->wert() ?? 0)), 2),
            'ohne_preis' => $gezaehlt->filter(fn ($l) => $l->price_per_base === null)->count(),
            'differenz_wert' => round($gezaehlt->sum(fn ($l) => $l->price_per_base !== null
                ? ((float) $l->qty_counted - (float) $l->qty_expected) * (float) $l->price_per_base : 0.0), 2),
        ];
    }

    /** Menge in der Anzeige-Einheit (kg/l/Stk) fürs Eingabefeld. */
    public function anzeigeMenge(?float $basis, string $baseUnit): ?float
    {
        if ($basis === null) {
            return null;
        }

        return in_array($baseUnit, ['g', 'ml'], true) ? round($basis / 1000, 3) : round($basis, 3);
    }

    public function anzeigeEinheit(string $baseUnit): string
    {
        return ['g' => 'kg', 'ml' => 'l'][$baseUnit] ?? $baseUnit;
    }

    // ── intern ──────────────────────────────────────────────────────────────

    private function offen(Team $team, int $countId): FoodAlchemistInventoryCount
    {
        $count = FoodAlchemistInventoryCount::where('team_id', $team->id)->findOrFail($countId);
        if ($count->istGebucht()) {
            throw new \RuntimeException('Diese Inventur ist bereits gebucht.');
        }

        return $count;
    }

    /** @param list<array{gp_id:?int, supplier_item_id:?int, base_unit:string, qty_expected:float}> $positionen */
    private function positionenAnlegen(Team $team, FoodAlchemistInventoryCount $count, array $positionen): void
    {
        $gps = FoodAlchemistGp::whereIn('id', array_filter(array_column($positionen, 'gp_id')))->get()->keyBy('id');
        $start = (int) $count->lines()->max('position');
        // alphabetisch nach Name — so liest sich die Zählliste beim Gang durchs Lager
        usort($positionen, fn ($a, $b) => strcmp(
            (string) ($gps->get($a['gp_id'])?->name ?? ''), (string) ($gps->get($b['gp_id'])?->name ?? '')));
        foreach ($positionen as $i => $p) {
            FoodAlchemistInventoryCountLine::create([
                'team_id' => $team->id, 'inventory_count_id' => $count->id,
                'gp_id' => $p['gp_id'], 'supplier_item_id' => $p['supplier_item_id'], 'base_unit' => $p['base_unit'],
                'qty_expected' => round((float) $p['qty_expected'], 4),
                'price_per_base' => $this->preisJeBasis($team, $p['gp_id'] !== null ? $gps->get($p['gp_id']) : null, $p['supplier_item_id'], $p['base_unit']),
                'position' => $start + $i + 1,
            ]);
        }
    }

    /** Bewertung: aktueller EK je Basiseinheit (GP: Lead/Durchschnitt; Artikel: aktiver Preis ÷ Inhalt). */
    private function preisJeBasis(Team $team, ?FoodAlchemistGp $gp, ?int $supplierItemId, string $baseUnit): ?float
    {
        if ($gp !== null) {
            $preis = $baseUnit === 'Stk' ? $this->recompute->preisProStueckPublic($gp, $team) : $this->recompute->preisProGrammPublic($gp, $team);

            return $preis !== null ? round($preis, 6) : null;
        }
        if ($supplierItemId === null) {
            return null;
        }
        $item = FoodAlchemistSupplierItem::find($supplierItemId);
        $price = $item !== null ? app(PriceService::class)->activeFor($item->id) : null;
        if ($item === null || $price === null || (float) $item->qty <= 0) {
            return null;
        }
        $jeEinheit = (float) $price->price / (float) $item->qty;   // € je kg/l/Stk

        return round($baseUnit === 'Stk' ? $jeEinheit : $jeEinheit / 1000, 6);
    }

    private function basisEinheit(string $unitCode): string
    {
        return match (strtolower(trim($unitCode))) {
            'kg', 'g' => 'g',
            'l', 'ml' => 'ml',
            'stk', 'stück', 'stueck' => 'Stk',
            default => '',
        };
    }

    private function schluessel(?int $gpId, ?int $supplierItemId, string $unit): string
    {
        return ($gpId !== null ? 'gp:' . $gpId : 'la:' . $supplierItemId) . ':' . $unit;
    }
}
