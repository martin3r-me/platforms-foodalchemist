<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
            ->orderByRaw('valid_to IS NULL ASC') // NULL-valid_to (unbefristet alt) hinter datierten — engine-agnostisch (07 §7)
            ->orderByDesc('valid_to')
            ->orderByDesc('id')
            ->first();
    }

    /** Subquery für Listen-Spalten (EK je Artikel) — gleiche Regel wie activeFor(). */
    public function activePriceSubquery(): Builder
    {
        return $this->scopeAktiv(FoodAlchemistPrice::query())
            ->select('price')
            ->whereColumn('foodalchemist_prices.supplier_item_id', 'foodalchemist_supplier_items.id')
            ->orderByRaw('valid_to IS NULL ASC')
            ->orderByDesc('valid_to')
            ->orderByDesc('id')
            ->limit(1);
    }

    /** Historie eines LA (alle Zeilen, neueste zuerst) inkl. abgeleiteter Kategorie (I2). */
    public function historyFor(int $supplierItemId): Collection
    {
        return FoodAlchemistPrice::query()
            ->where('supplier_item_id', $supplierItemId)
            ->orderByRaw('valid_to IS NULL ASC')
            ->orderByDesc('valid_to')
            ->orderByDesc('id')
            ->get()
            ->each(fn ($p) => $p->setAttribute(
                'kategorie',
                PriceCategory::fuer($p->price !== null ? (float) $p->price : null, $p->status),
            ));
    }

    /**
     * §3.2 Vergleichspreis: Gebindepreis → €/kg | €/l | €/Stk (Anzeige-Normalisierung).
     * qty NULL/0 ⇒ NULL (I4, nie Division), price < 0 ⇒ NULL (I5), unbekannte Einheit ⇒ NULL.
     *
     * @return array{wert: float, einheit: string}|null
     */
    public function vergleichspreis(FoodAlchemistSupplierItem $item, ?float $preis): ?array
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
    public function preisProGramm(FoodAlchemistSupplierItem $item, ?float $preis): ?float
    {
        $v = $this->vergleichspreis($item, $preis);

        return match ($v['einheit'] ?? null) {
            '€/kg', '€/l' => $v['wert'] / 1000,
            default => null,
        };
    }
}
