<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\PriceCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * M2-04/05 / GL-11: DIE eine Stelle für Preislogik — aktiver Preis (§3.3),
 * Kategorisierung (§3.1, via PriceCategory), Vergleichspreis-Normalisierung (§3.2).
 * Konsumenten: GpService::lasForGp, SupplierItemService (EK-Spalte), ItemModal,
 * später GL-02-Recompute + GL-03-Lead-Wahl.
 */
class PriceService
{
    /** Aktiv-Bedingung (I3) index-fähig ausformuliert: price >= 0 AND NOT NULL AND status IN ('0','2'). */
    public function scopeAktiv(Builder $query): Builder
    {
        return $query
            ->where('is_blocked', false)
            ->whereNotNull('price')
            ->where('price', '>=', 0)
            ->whereIn('status', ['0', '2']);
    }

    /** §3.3: aktiver Preis = NEUESTE aktive Zeile (valid_to DESC, Tiebreak id DESC). */
    public function activeFor(int $supplierItemId): ?FoodAlchemistPrice
    {
        return $this->scopeAktiv(FoodAlchemistPrice::query())
            ->where('supplier_item_id', $supplierItemId)
            ->orderByRaw('valid_to IS NULL DESC') // NULL = unbefristet = AKTUELL gültig, rankt VOR datierten (Append-only: alte Zeile wird gestempelt) — engine-agnostisch (07 §7); Realdaten: 108.310/111.543 aktive Zeilen sind NULL
            ->orderByDesc('valid_to')
            ->orderByDesc('id')
            ->first();
    }

    /** Subquery für Listen-Spalten (EK je Artikel) — gleiche Regel wie activeFor(). $outerColumn = Spalte der äußeren Query (bei Alias z. B. 'i.id'). */
    public function activePriceSubquery(string $outerColumn = 'foodalchemist_supplier_items.id'): Builder
    {
        return $this->scopeAktiv(FoodAlchemistPrice::query())
            ->select('price')
            ->whereColumn('foodalchemist_prices.supplier_item_id', $outerColumn)
            ->orderByRaw('valid_to IS NULL DESC')
            ->orderByDesc('valid_to')
            ->orderByDesc('id')
            ->limit(1);
    }

    /**
     * Preis-Trend je Item: aktuell vs. vorheriger aktiver Preis (gleiche Aktiv-Ordnung
     * wie activeFor, Offset 1 — Append-only stempelt den Vorgänger mit valid_to). Bulk
     * gegen N+1. Nur Items MIT Vorgänger und positivem Vorpreis (Delta% berechenbar).
     *
     * `plausibel=false`, wenn Vorpreis/aktuell um Faktor ≥10 auseinanderliegen — dann
     * ist der Vorpreis fast sicher ein Platzhalter/Dummy (z.B. 999). Der Aufrufer zeigt
     * dann ein ⚠-Flag statt eines irreführenden Delta-% (Hybrid: Datenmüll sichtbar,
     * Anzeige sauber).
     *
     * @param array<int> $itemIds
     * @return array<int, array{aktuell: float, vorher: float, delta_pct: float, plausibel: bool}>
     */
    public function preisTrendBulk(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        if ($itemIds === []) {
            return [];
        }

        $rows = $this->scopeAktiv(FoodAlchemistPrice::query())
            ->whereIn('supplier_item_id', $itemIds)
            ->orderBy('supplier_item_id')
            ->orderByRaw('valid_to IS NULL DESC')
            ->orderByDesc('valid_to')
            ->orderByDesc('id')
            ->get(['supplier_item_id', 'price']);

        $proItem = [];
        foreach ($rows as $r) {
            $proItem[(int) $r->supplier_item_id][] = (float) $r->price;
        }

        $out = [];
        foreach ($proItem as $id => $preise) {
            if (count($preise) >= 2 && $preise[1] > 0 && $preise[0] > 0) {
                $faktor = max($preise[0], $preise[1]) / min($preise[0], $preise[1]);
                $out[$id] = [
                    'aktuell' => $preise[0],
                    'vorher' => $preise[1],
                    'delta_pct' => round(($preise[0] - $preise[1]) / $preise[1] * 100, 1),
                    'plausibel' => $faktor < 10,       // Faktor ≥10 ⇒ Vorpreis fast sicher Platzhalter
                ];
            }
        }

        return $out;
    }

    /** Historie eines LA (alle Zeilen, neueste zuerst) inkl. abgeleiteter Kategorie (I2). */
    public function historyFor(int $supplierItemId): Collection
    {
        return FoodAlchemistPrice::query()
            ->where('supplier_item_id', $supplierItemId)
            ->orderByRaw('valid_to IS NULL DESC')
            ->orderByDesc('valid_to')
            ->orderByDesc('id')
            ->get()
            ->each(fn ($p) => $p->setAttribute(
                'kategorie',
                PriceCategory::fuer($p->price !== null ? (float) $p->price : null, $p->status),
            ));
    }

    /**
     * M2-08 / P-6: „+ Neuer Preis schließt Vorgänger" — Append-only (GL-11 §3.3 Historie):
     * der bisher aktive Preis bekommt valid_to = jetzt, die neue Zeile ist unbefristet (NULL)
     * und damit sofort aktiv. Nur Besitzer-Team des LA (D1).
     */
    public function createFor(Team $team, FoodAlchemistSupplierItem $item, float $preis, string $status = '0'): FoodAlchemistPrice
    {
        if (! $item->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Katalog-Artikel — Preispflege nur durch das Besitzer-Team (D1).');
        }
        if ($preis < 0) {
            throw new \RuntimeException('Negative Preise sind Service-Zuschläge — keine manuelle Anlage (GL-11 I5).');
        }
        if (! in_array($status, ['0', '2'], true)) {
            throw new \RuntimeException('Status muss 0 (Standard-EK) oder 2 (Aktion) sein.');
        }

        return DB::transaction(function () use ($team, $item, $preis, $status) {
            $this->activeFor($item->id)?->update(['valid_to' => now()]); // schließt Vorgänger

            return FoodAlchemistPrice::create([
                'team_id' => $team->id,
                'supplier_item_id' => $item->id,
                'price' => $preis,
                'status' => $status,
                'valid_to' => null,
                'is_blocked' => false,
                'creation_date' => now(),
            ]);
        });
    }

    /** M2-08: Preiszeile löschen — nur Besitzer-Team des LA. */
    public function deleteFor(Team $team, FoodAlchemistSupplierItem $item, int $priceId): void
    {
        if (! $item->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Katalog-Artikel — Preispflege nur durch das Besitzer-Team (D1).');
        }
        FoodAlchemistPrice::where('supplier_item_id', $item->id)->whereKey($priceId)->firstOrFail()->delete();
    }

    /**
     * M2-12 / V-Register: Preis-Anomalien — (a) Sprünge > x % zwischen aufeinander-
     * folgenden Preis-Generationen eines LA, (b) Vergleichspreis-Ausreißer je
     * Warengruppe (Faktor ≥ x vom WG-Median, gleiche Einheit). PHP-seitige
     * Median-Rechnung = engine-agnostisch (07 §7).
     *
     * @return array{spruenge: \Illuminate\Support\Collection, ausreisser: \Illuminate\Support\Collection}
     */
    public function detectAnomalies(Team $team, float $sprungPct = 30.0, float $ausreisserFaktor = 4.0): array
    {
        // (a) Generationen-Sprünge: LAs mit >1 Preiszeile
        $mehrfach = FoodAlchemistPrice::query()
            ->whereIn('supplier_item_id', FoodAlchemistSupplierItem::visibleToTeam($team)->select('id'))
            ->selectRaw('supplier_item_id, COUNT(*) AS n')
            ->groupBy('supplier_item_id')->having('n', '>', 1)
            ->pluck('supplier_item_id');

        $spruenge = collect();
        foreach ($mehrfach as $itemId) {
            $zeilen = $this->historyFor($itemId)->filter(fn ($p) => $p->price !== null && (float) $p->price > 0)->values();
            for ($i = 0; $i < $zeilen->count() - 1; $i++) {
                $neu = (float) $zeilen[$i]->price;
                $alt = (float) $zeilen[$i + 1]->price;
                $pct = abs($neu - $alt) / $alt * 100;
                if ($pct > $sprungPct) {
                    $spruenge->push((object) ['supplier_item_id' => $itemId, 'von' => $alt, 'nach' => $neu, 'sprung_pct' => round($pct, 1)]);
                }
            }
        }

        // (b) WG-Ausreißer: flache Join-Query ohne Eloquent-Modelle (Memory + Tempo)
        $kandidaten = \Illuminate\Support\Facades\DB::table('foodalchemist_supplier_items AS i')
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'i.id')
            ->join('foodalchemist_gps AS g', 'g.id', '=', 's.gp_id')
            ->join('foodalchemist_suppliers AS sup', 'sup.id', '=', 'i.supplier_id')
            ->whereIn('i.team_id', FoodAlchemistSupplierItem::teamAncestryIds($team))
            ->whereNull('i.deleted_at')->whereNull('s.deleted_at')
            ->whereNotNull('i.qty')->where('i.qty', '>', 0)->whereNotNull('i.unit_code')
            ->select(['i.id', 'i.designation', 'i.qty', 'i.unit_code', 'sup.name AS lieferant', 'g.warengruppe_code AS wg'])
            ->selectSub($this->activePriceSubquery('i.id')->toBase(), 'aktiver_preis')
            ->get()
            ->filter(fn ($i) => $i->aktiver_preis !== null && (float) $i->aktiver_preis > 0)
            ->map(function ($i) {
                $v = $this->vergleichspreis($i, (float) $i->aktiver_preis);

                return $v === null ? null : (object) [
                    'id' => $i->id,
                    'bezeichnung' => $i->designation,
                    'lieferant' => $i->lieferant,
                    'wg' => $i->wg ?? '–',
                    'einheit' => $v['einheit'],
                    'wert' => $v['wert'],
                ];
            })
            ->filter();

        $ausreisser = collect();
        foreach ($kandidaten->groupBy(fn ($k) => $k->wg . '|' . $k->einheit) as $gruppe) {
            if ($gruppe->count() < 4) {
                continue; // zu wenig Daten für einen belastbaren Median
            }
            $median = $gruppe->pluck('wert')->sort()->values()->get((int) floor($gruppe->count() / 2));
            foreach ($gruppe as $k) {
                $faktor = $median > 0 ? max($k->wert / $median, $median / max($k->wert, 1e-9)) : 0;
                if ($faktor >= $ausreisserFaktor) {
                    $ausreisser->push((object) [
                        'id' => $k->id, 'bezeichnung' => $k->bezeichnung, 'lieferant' => $k->lieferant,
                        'wg' => $k->wg, 'einheit' => $k->einheit,
                        'wert' => round($k->wert, 2), 'median' => round($median, 2), 'faktor' => round($faktor, 1),
                    ]);
                }
            }
        }

        return [
            'spruenge' => $spruenge->sortByDesc('sprung_pct')->values(),
            'ausreisser' => $ausreisser->sortByDesc('faktor')->values(),
        ];
    }

    /**
     * §3.2 Vergleichspreis: Gebindepreis → €/kg | €/l | €/Stk (Anzeige-Normalisierung).
     * qty NULL/0 ⇒ NULL (I4, nie Division), price < 0 ⇒ NULL (I5), unbekannte Einheit ⇒ NULL.
     *
     * @return array{wert: float, einheit: string}|null
     */
    public function vergleichspreis(object $item, ?float $preis): ?array
    {
        if ($preis === null || $preis < 0) {
            return null;
        }
        $qty = $item->qty !== null ? (float) $item->qty : null;
        if ($qty === null || $qty == 0.0) {
            return null; // I4 — GT-5: 47,50 € mit qty NULL ⇒ kein Vergleichspreis
        }

        return match ($item->unit_code) {
            'kg' => ['wert' => $preis / $qty, 'einheit' => '€/kg'],
            'l' => ['wert' => $preis / $qty, 'einheit' => '€/l'],
            'Stk' => ['wert' => $preis / $qty, 'einheit' => '€/Stk'],
            default => null,
        };
    }

    /** §3.2 GL-02-Sicht: €/g (kg+l über Dichte 1.0; Stk nur via Stückgewichts-Brücke). */
    public function preisProGramm(object $item, ?float $preis): ?float
    {
        $v = $this->vergleichspreis($item, $preis);

        return match ($v['einheit'] ?? null) {
            '€/kg', '€/l' => $v['wert'] / 1000,
            default => null,
        };
    }
}
