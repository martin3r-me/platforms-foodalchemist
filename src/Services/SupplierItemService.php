<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Models\FoodAlchemistItemNutritional;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
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
        $q = trim($filters['q'] ?? '');

        return $this->baseQuery($team, $filters)
            ->where('supplier_id', $supplierId)
            // M2-14: Suche INNERHALB des Lieferanten (Ist-App-Screen 2)
            ->when($q !== '', fn (Builder $w) => $w->where(fn (Builder $x) => $x
                ->whereRaw('LOWER(designation) LIKE ?', ['%' . mb_strtolower($q) . '%'])
                ->orWhere('article_number', 'like', $q . '%')))
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
            ->with(['structure.gp:id,name,lead_la_supplier_item_id'])
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

    // ── LA-Nährwerte (je 100 g) — speisen die GP-Aggregation (GL-08) ────

    /** Kern-Nährwerte fürs Item-Modal: Feld → [Label, Einheit] (je 100 g). */
    public const NAEHRWERT_FELDER = [
        'energy_kcal' => ['Energie', 'kcal'],
        'energy_kj' => ['Energie', 'kJ'],
        'protein' => ['Eiweiß', 'g'],
        'fat' => ['Fett', 'g'],
        'carbs_absorbable' => ['Kohlenhydrate', 'g'],
        'sodium' => ['Natrium', 'g'],
    ];

    /** Kern-Nährwerte des LA als UI-Form (Strings, leer = kein Wert). */
    public function getNutrition(FoodAlchemistSupplierItem $item): array
    {
        $zeile = $item->nutritionals;

        return collect(array_keys(self::NAEHRWERT_FELDER))
            ->mapWithKeys(fn (string $k) => [$k => $zeile?->{$k} !== null ? (string) (float) $zeile->{$k} : ''])
            ->all();
    }

    /** Edit nur Besitzer-Team (D1). Leer ⇒ NULL; negativ/nicht-numerisch ⇒ NULL (kein stiller 0). */
    public function setNutrition(Team $team, FoodAlchemistSupplierItem $item, array $werte): FoodAlchemistItemNutritional
    {
        if (! $item->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Katalog-Artikel — Nährwert-Pflege nur durch das Besitzer-Team (D1).');
        }

        $num = function ($v) {
            $roh = trim((string) $v);
            if ($roh === '') {
                return null;
            }
            $f = (float) str_replace(',', '.', $roh);

            return $f >= 0 ? $f : null;
        };
        $attribute = [];
        foreach (array_keys(self::NAEHRWERT_FELDER) as $k) {
            $attribute[$k] = $num($werte[$k] ?? '');
        }

        return FoodAlchemistItemNutritional::updateOrCreate(
            ['supplier_item_id' => $item->id],
            [...$attribute, 'team_id' => $item->team_id],
        );
    }

    // ── LA-Deklarationen (M2-15, GL-09) ─────────────────────────────────

    /** 18 LMIV-Werte des LA als UI-Form: 'ja'|'nein'|'unbekannt' (Quelle 3|1|0/NULL). */
    public function getDeclarations(FoodAlchemistSupplierItem $item): array
    {
        $zeile = $item->declarations;

        return collect(FoodAlchemistItemDeclaration::STOFFE)->keys()
            ->mapWithKeys(fn (string $k) => [$k => match ((int) ($zeile?->{$k} ?? 0)) {
                3 => 'ja',
                1 => 'nein',
                default => 'unbekannt',
            }])
            ->all();
    }

    /** Edit nur Besitzer-Team; manuelle Pflege stempelt quelle=manual. Schreibt ROHE Domäne (GL-09 A1). */
    public function setDeclarations(Team $team, FoodAlchemistSupplierItem $item, array $werte): FoodAlchemistItemDeclaration
    {
        if (! $item->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Katalog-Artikel — Deklarations-Pflege nur durch das Besitzer-Team (D1).');
        }

        $attribute = [];
        foreach (array_keys(FoodAlchemistItemDeclaration::STOFFE) as $k) {
            $wert = $werte[$k] ?? 'unbekannt';
            $attribute[$k] = match ($wert) {
                'ja' => 3,
                'nein' => 1,
                'unbekannt' => 0,
                default => throw new \RuntimeException("Ungültiger Deklarations-Wert [{$wert}] für {$k}."),
            };
        }

        return FoodAlchemistItemDeclaration::updateOrCreate(
            ['supplier_item_id' => $item->id],
            [...$attribute, 'team_id' => $item->team_id, 'quelle' => 'manual'],
        );
    }

    // ── Artikel-CRUD (M2-11, D-2 §4 + D1) ───────────────────────────────

    /**
     * „+ Neuer Artikel": Minimal-Pflichtfelder, gehört IMMER dem anlegenden Team —
     * Kind-Teams ergänzen Eigenes am geerbten Lieferanten (D1; Eltern sehen es nicht).
     */
    public function create(Team $team, int $supplierId, array $input): FoodAlchemistSupplierItem
    {
        if (! FoodAlchemistSupplier::visibleToTeam($team)->whereKey($supplierId)->exists()) {
            throw new \RuntimeException('Lieferant nicht in der Team-Kette sichtbar.');
        }
        $designation = trim($input['designation'] ?? '');
        if ($designation === '') {
            throw new \RuntimeException('Bezeichnung ist Pflicht.');
        }

        return FoodAlchemistSupplierItem::create([
            'team_id' => $team->id,
            'supplier_id' => $supplierId,
            'designation' => $designation,
            'article_number' => ($input['article_number'] ?? '') ?: null,
            'qty' => ($input['qty'] ?? '') !== '' ? (float) str_replace(',', '.', (string) $input['qty']) : null,
            'unit_code' => in_array($input['unit_code'] ?? '', ['kg', 'l', 'Stk'], true) ? $input['unit_code'] : null,
        ]);
    }

    /** Deaktivieren = soft (is_discontinued), nie löschen — nur Besitzer-Team (D1). */
    public function setDiscontinued(Team $team, FoodAlchemistSupplierItem $item, bool $discontinued): void
    {
        if (! $item->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Katalog-Artikel — nur das Besitzer-Team darf (de)aktivieren (D1).');
        }
        $item->update(['is_discontinued' => $discontinued]);
    }
}
