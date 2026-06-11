<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * M2-02/03 / D-2: Artikel-Listen des Lieferanten-Browsers + lieferantenübergreifende Suche.
 *
 * EK-Spalte = aktiver Preis. Die Aktiv-Preis-REGEL wandert mit M2-04 in den
 * PriceService (eine Stelle, GL-11) — hier wird nur dessen Subquery eingebunden.
 */
class SupplierItemService
{
    public function paginateForSupplier(Team $team, int $supplierId, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->baseQuery($team, $filters)
            ->where('supplier_id', $supplierId)
            ->orderBy('designation')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** P-7: Suche über ALLE Lieferanten (eigene „Route" via ?q=, V-17). */
    public function searchGlobal(Team $team, string $q, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->baseQuery($team, $filters)
            ->with('supplier:id,name')
            ->where(fn (Builder $w) => $w
                ->whereRaw('LOWER(designation) LIKE ?', ['%' . mb_strtolower($q) . '%'])
                ->orWhere('article_number', 'like', $q . '%'))
            ->orderBy('designation')
            ->paginate($perPage)
            ->withQueryString();
    }

    private function baseQuery(Team $team, array $filters): Builder
    {
        return FoodAlchemistSupplierItem::visibleToTeam($team)
            ->with(['structure.gp:id,name'])
            ->when($filters['onlyActive'] ?? true, fn ($q) => $q->where('is_discontinued', false))
            ->addSelect([
                '*',
                'aktiver_preis' => app(PriceService::class)->activePriceSubquery(),
            ]);
    }

    // ── LA-Allergene (M2-10, GL-01) ─────────────────────────────────────

    /** 14 EU-Werte des LA (NULL ⇒ 'unbekannt' — GL-01 4-Wert-Modell, nie Lücken). */
    public function getAllergens(FoodAlchemistSupplierItem $item): array
    {
        $zeile = $item->allergens;

        return collect(FoodAlchemistItemAllergen::ALLERGENE)->keys()
            ->mapWithKeys(fn (string $k) => [$k => $zeile?->{"allergen_{$k}"} ?? 'unbekannt'])
            ->all();
    }

    /** Edit nur Besitzer-Team (D1); manuelle Pflege setzt quelle='manual' (GL-07-Lineage). */
    public function setAllergens(Team $team, FoodAlchemistSupplierItem $item, array $werte): FoodAlchemistItemAllergen
    {
        if (! $item->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Katalog-Artikel — Allergen-Pflege nur durch das Besitzer-Team (D1).');
        }

        $erlaubt = ['enthalten', 'spuren', 'nicht_enthalten', 'unbekannt'];
        $attribute = [];
        foreach (array_keys(FoodAlchemistItemAllergen::ALLERGENE) as $k) {
            $wert = $werte[$k] ?? 'unbekannt';
            if (! in_array($wert, $erlaubt, true)) {
                throw new \RuntimeException("Ungültiger Allergen-Wert [{$wert}] für {$k}.");
            }
            $attribute["allergen_{$k}"] = $wert === 'unbekannt' ? null : $wert;
        }

        return FoodAlchemistItemAllergen::updateOrCreate(
            ['supplier_item_id' => $item->id],
            [...$attribute, 'team_id' => $item->team_id, 'quelle' => 'manual'],
        );
    }
}
