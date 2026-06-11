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

    /** Subquery für Listen-Spalten (EK je Artikel) — gleiche Regel wie activeFor(). */
    public function activePriceSubquery(): Builder
    {
        return $this->scopeAktiv(FoodAlchemistPrice::query())
            ->select('price')
            ->whereColumn('foodalchemist_prices.supplier_item_id', 'foodalchemist_supplier_items.id')
            ->orderByRaw('valid_to IS NULL DESC')
            ->orderByDesc('valid_to')
            ->orderByDesc('id')
            ->limit(1);
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
