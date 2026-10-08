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
        private LagerEinrichtungService $einrichtung,
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
            ->with(['location', 'lines.gp:id,name,piece_default_g,condition,commodity_group_code', 'lines.supplierItem:id,designation,article_number', 'lines.bin:id,name,sort_order,zone'])
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
        $ohneAufteilung = ['counted_packs' => null, 'counted_units' => null, 'counted_loose' => null];
        if ($roh === '') {
            $line->update(['qty_counted' => null] + $ohneAufteilung);

            return $line->refresh();
        }
        if (! is_numeric($roh) || (float) $roh < 0) {
            throw new \RuntimeException('Gezählte Menge braucht eine Zahl ≥ 0.');
        }
        $faktor = in_array($line->base_unit, ['g', 'ml'], true) ? 1000.0 : 1.0;
        $line->update(['qty_counted' => round((float) $roh * $faktor, 4)] + $ohneAufteilung);

        return $line->refresh();
    }

    /**
     * Spec 66b · Zählen wie im Regal: Kartons + Einheiten + lose Menge (kg/l/Stk). Die Summe landet
     * in qty_counted (Basiseinheit), die Aufteilung bleibt für Anzeige und Druck stehen.
     * Alle drei leer = nicht gezählt.
     */
    public function zaehlenGebinde(Team $team, int $lineId, mixed $kartons, mixed $einheiten, mixed $lose): FoodAlchemistInventoryCountLine
    {
        $line = FoodAlchemistInventoryCountLine::where('team_id', $team->id)->findOrFail($lineId);
        $this->offen($team, (int) $line->inventory_count_id);
        $zahl = function (mixed $v, string $was): ?float {
            $roh = trim(str_replace(',', '.', (string) ($v ?? '')));
            if ($roh === '') {
                return null;
            }
            if (! is_numeric($roh) || (float) $roh < 0) {
                throw new \RuntimeException($was . ' braucht eine Zahl ≥ 0.');
            }

            return (float) $roh;
        };
        $k = $line->pack_units !== null ? $zahl($kartons, 'Kartons') : null;
        $e = $line->hatGebinde() ? $zahl($einheiten, 'Einheiten') : null;
        $l = $zahl($lose, 'Lose Menge');
        if ($k === null && $e === null && $l === null) {
            $line->update(['qty_counted' => null, 'counted_packs' => null, 'counted_units' => null, 'counted_loose' => null]);

            return $line->refresh();
        }
        $faktor = in_array($line->base_unit, ['g', 'ml'], true) ? 1000.0 : 1.0;
        $unit = (float) ($line->unit_base ?? 0);
        $summe = ($k ?? 0) * (float) ($line->pack_units ?? 0) * $unit + ($e ?? 0) * $unit + ($l ?? 0) * $faktor;
        $line->update([
            'qty_counted' => round($summe, 4),
            'counted_packs' => $k, 'counted_units' => $e, 'counted_loose' => $l !== null ? round($l, 4) : null,
        ]);

        return $line->refresh();
    }

    /**
     * Offene Inventur buchen: Bestand := gezählt, Differenz als Bewegung. Mit $nichtGezaehltNull
     * zählen offene Positionen als 0 („was nicht gezählt ist, ist nicht da" — wie necta „Lager leeren").
     */
    public function buchen(Team $team, int $countId, ?int $userId = null, bool $nichtGezaehltNull = false): FoodAlchemistInventoryCount
    {
        $count = $this->offen($team, $countId);

        return DB::transaction(function () use ($team, $count, $userId, $nichtGezaehltNull) {
            if ($nichtGezaehltNull) {
                $count->lines()->whereNull('qty_counted')->update(['qty_counted' => 0]);
            }
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
            $count->update(['status' => 'gebucht', 'booked_at' => now(), 'booked_by' => $userId, 'value_total' => round($wert, 2), 'uncounted_zeroed' => $nichtGezaehltNull]);

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

    /**
     * Bestand je Grundprodukt/Artikel und Lagerort, bewertet mit dem aktuellen EK.
     *
     * @return list<array{stock_id:int, gp_id:?int, name:string, lagerort:string, base_unit:string, menge:float, anzeige:string, preis:?float, wert:?float, zuletzt:?string}>
     */
    public function bestand(Team $team, ?int $locationId = null, string $suche = ''): array
    {
        $stocks = FoodAlchemistInventoryStock::where('team_id', $team->id)
            ->with(['gp:id,name,piece_default_g,lead_la_supplier_item_id,condition,commodity_group_code', 'gp.leadLa:id,supplier_id', 'location:id,name', 'supplierItem:id,designation,qty,supplier_id'])
            ->when($locationId !== null, fn ($q) => $q->where('inventory_location_id', $locationId))
            ->where('qty_base', '<>', 0)->get();
        $zuletzt = FoodAlchemistInventoryMovement::where('team_id', $team->id)
            ->whereIn('stock_id', $stocks->pluck('id')->all() ?: [0])
            ->selectRaw('stock_id, MAX(moved_at) as zuletzt')->groupBy('stock_id')->pluck('zuletzt', 'stock_id');
        // Spec 66b: Stammplatz je (Lagerort, GP) für Anzeige + Filter
        $stamm = \Platform\FoodAlchemist\Models\FoodAlchemistStorageBinItem::where('team_id', $team->id)->with('bin:id,name,sort_order')
            ->get()->keyBy(fn ($z) => $z->inventory_location_id . ':' . $z->gp_id);
        $rows = [];
        foreach ($stocks as $s) {
            $name = $s->gp?->name ?? $s->supplierItem?->designation ?? '—';
            if ($suche !== '' && ! str_contains(mb_strtolower($name), mb_strtolower($suche))) {
                continue;
            }
            $preis = $this->preisJeBasis($team, $s->gp, $s->gp === null ? $s->supplier_item_id : null, $s->base_unit);
            $menge = (float) $s->qty_base;
            $rows[] = [
                'stock_id' => (int) $s->id, 'gp_id' => $s->gp_id !== null ? (int) $s->gp_id : null, 'name' => $name,
                'lagerort' => (string) ($s->location?->name ?? '—'), 'base_unit' => $s->base_unit, 'menge' => $menge,
                'anzeige' => $this->inventory->displayQuantity($menge, $s->base_unit),
                'preis' => $preis, 'wert' => $preis !== null ? round($menge * $preis, 2) : null,
                'zuletzt' => isset($zuletzt[$s->id]) ? Carbon::parse($zuletzt[$s->id])->format('d.m.Y') : null,
                'zuletzt_iso' => isset($zuletzt[$s->id]) ? Carbon::parse($zuletzt[$s->id])->toDateString() : null,
                'location_id' => (int) $s->inventory_location_id,
                'bin_id' => $stamm->get($s->inventory_location_id . ':' . $s->gp_id)?->storage_bin_id,
                'stellplatz' => $stamm->get($s->inventory_location_id . ':' . $s->gp_id)?->bin?->name,
                'zustand' => $s->gp?->condition ?: null,
                'warengruppe' => $s->gp?->commodity_group_code ?: null,
                'lieferant_id' => $s->gp?->leadLa?->supplier_id ?? $s->supplierItem?->supplier_id,
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $rows;
    }

    /** Jüngste Lagerbewegungen (Wareneingang, Inventur …). @return \Illuminate\Support\Collection */
    public function bewegungen(Team $team, ?string $quelle = null, int $limit = 200)
    {
        return FoodAlchemistInventoryMovement::where('team_id', $team->id)
            ->with(['gp:id,name', 'supplierItem:id,designation', 'location:id,name', 'order:id,supplier_id', 'order.supplier:id,name'])
            ->when($quelle !== null && $quelle !== '', fn ($q) => $q->where('source', $quelle))
            ->where('qty_base', '<>', 0)
            ->orderByDesc('moved_at')->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Spec 66b · Smarte Filter für Bestand und Zählliste. Leere Werte filtern nicht.
     * Schlüssel: suche, stellplatz (id | 'ohne'), zustand, warengruppe, lieferant, ohne_preis (bool),
     * ladenhueter (bool, Bestand: seit 90 Tagen keine Bewegung), status (offen | gezaehlt | differenz).
     *
     * @param  array<string, mixed>  $f
     */
    public function filterBestand(array $rows, array $f): array
    {
        $grenze = now()->subDays(self::VORBELEGUNG_TAGE)->toDateString();

        return array_values(array_filter($rows, fn ($r) => $this->passt($f, $r['name'], $r['bin_id'] ?? null, $r['zustand'] ?? null, $r['warengruppe'] ?? null, $r['lieferant_id'] ?? null)
            && (empty($f['ohne_preis']) || $r['wert'] === null)
            && (empty($f['ladenhueter']) || ($r['zuletzt_iso'] ?? null) === null || $r['zuletzt_iso'] < $grenze)));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, FoodAlchemistInventoryCountLine>  $lines
     * @param  array<string, mixed>  $f
     * @return \Illuminate\Support\Collection<int, FoodAlchemistInventoryCountLine>
     */
    public function filterZeilen($lines, array $f)
    {
        $schwelle = max(0.0, (float) ($f['differenz_pct'] ?? 10));

        return $lines->filter(function ($l) use ($f, $schwelle) {
            $name = (string) ($l->gp?->name ?? $l->supplierItem?->designation ?? '');
            if (! $this->passt($f, $name, $l->storage_bin_id, $l->gp?->condition, $l->gp?->commodity_group_code, null)) {
                return false;
            }
            if (! empty($f['ohne_preis']) && $l->price_per_base !== null) {
                return false;
            }

            return match ($f['status'] ?? '') {
                'offen' => $l->qty_counted === null,
                'gezaehlt' => $l->qty_counted !== null,
                'differenz' => $l->qty_counted !== null && ((float) $l->qty_expected <= 0
                    ? (float) $l->qty_counted > 0
                    : abs((float) $l->qty_counted - (float) $l->qty_expected) / (float) $l->qty_expected * 100 > $schwelle),
                default => true,
            };
        })->values();
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

    /** @param array<string, mixed> $f */
    private function passt(array $f, string $name, $binId, ?string $zustand, ?string $wg, $lieferantId): bool
    {
        $suche = trim((string) ($f['suche'] ?? ''));
        $platz = (string) ($f['stellplatz'] ?? '');

        return ($suche === '' || str_contains(mb_strtolower($name), mb_strtolower($suche)))
            && ($platz === '' || ($platz === 'ohne' ? $binId === null : (int) $platz === (int) $binId))
            && (empty($f['zustand']) || mb_strtolower((string) $zustand) === mb_strtolower((string) $f['zustand']))
            && (empty($f['warengruppe']) || (string) $wg === (string) $f['warengruppe'])
            && (empty($f['lieferant']) || (int) $lieferantId === (int) $f['lieferant']);
    }

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
        $gps = FoodAlchemistGp::with('leadLa')->whereIn('id', array_filter(array_column($positionen, 'gp_id')))->get()->keyBy('id');
        $items = FoodAlchemistSupplierItem::whereIn('id', array_filter(array_column($positionen, 'supplier_item_id')))->get()->keyBy('id');
        $start = (int) $count->lines()->max('position');
        // Spec 66b: Laufweg — erst nach Stellplatz (Reihenfolge), dann alphabetisch; ohne Stellplatz zuletzt
        $stamm = $this->einrichtung->stammplaetze($team, (int) $count->inventory_location_id);
        $reihenfolge = \Platform\FoodAlchemist\Models\FoodAlchemistStorageBin::whereIn('id', array_values($stamm) ?: [0])->pluck('sort_order', 'id');
        $rang = fn ($p) => $p['gp_id'] !== null && isset($stamm[$p['gp_id']]) ? (int) ($reihenfolge[$stamm[$p['gp_id']]] ?? PHP_INT_MAX - 1) : PHP_INT_MAX;
        $name = fn ($p) => (string) ($p['gp_id'] !== null ? $gps->get($p['gp_id'])?->name : $items->get($p['supplier_item_id'])?->designation);
        usort($positionen, fn ($a, $b) => [$rang($a), $name($a)] <=> [$rang($b), $name($b)]);
        foreach ($positionen as $i => $p) {
            $gp = $p['gp_id'] !== null ? $gps->get($p['gp_id']) : null;
            $gebinde = $this->einrichtung->gebinde($gp !== null ? $gp->leadLa : $items->get($p['supplier_item_id']), $p['base_unit']);
            FoodAlchemistInventoryCountLine::create([
                'team_id' => $team->id, 'inventory_count_id' => $count->id,
                'gp_id' => $p['gp_id'], 'supplier_item_id' => $p['supplier_item_id'], 'base_unit' => $p['base_unit'],
                'qty_expected' => round((float) $p['qty_expected'], 4),
                'price_per_base' => $this->preisJeBasis($team, $gp, $p['supplier_item_id'], $p['base_unit']),
                'position' => $start + $i + 1,
                'storage_bin_id' => $p['gp_id'] !== null ? ($stamm[$p['gp_id']] ?? null) : null,
            ] + ($gebinde ?? []));
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
