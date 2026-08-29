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
    /**
     * Erlaubte Kalkulationseinheiten am LA (GL-11). Bewusst hier als Konstante und
     * nicht als Literal in `create()`: der Kanal-B-Datei-Import (Spec 13 · S1a) prüft
     * dieselbe Liste, und zwei Whitelists für dieselbe Regel driften auseinander.
     */
    public const UNIT_CODES = ['kg', 'l', 'Stk'];

    public function paginateForSupplier(Team $team, int $supplierId, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $q = trim($filters['q'] ?? '');

        return $this->baseQuery($team, $filters)
            ->where('supplier_id', $supplierId)
            // M2-14: Suche INNERHALB des Lieferanten (Ist-App-Screen 2) — Multi-Wort:
            // jedes Token muss treffen (Bezeichnung ODER Artikelnummer-Präfix)
            ->when($q !== '', function (Builder $w) use ($q) {
                foreach (\Platform\FoodAlchemist\Support\Suche::tokens($q) as $token) {
                    $w->where(fn (Builder $x) => $x
                        ->whereRaw('LOWER(designation) LIKE ?', ['%' . $token . '%'])
                        ->orWhere('article_number', 'like', $token . '%'));
                }
            })
            ->orderBy('designation')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** P-7: Suche über ALLE Lieferanten (eigene „Route" via ?q=, V-17). */
    public function searchGlobal(Team $team, string $q, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->baseQuery($team, $filters)->with('supplier:id,name');
        // Multi-Wort: jedes Token muss treffen (Bezeichnung ODER Artikelnummer-Präfix)
        foreach (\Platform\FoodAlchemist\Support\Suche::tokens($q) as $token) {
            $query->where(fn (Builder $x) => $x
                ->whereRaw('LOWER(designation) LIKE ?', ['%' . $token . '%'])
                ->orWhere('article_number', 'like', $token . '%'));
        }

        return $query
            ->orderBy('designation')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Sichtbare LAs nach IDs, in derselben Form wie searchGlobal (structure.gp eager,
     * aktiver_preis-Subquery) — damit von der Semantik nachgereichte Kandidaten im
     * LaCandidateFinder dieselbe Ranking-Sicht haben wie die lexikalischen. Reihenfolge
     * egal (der Finder rankt selbst neu).
     *
     * @param  list<int>  $ids
     * @return \Illuminate\Database\Eloquent\Collection<int, FoodAlchemistSupplierItem>
     */
    public function byIds(Team $team, array $ids): \Illuminate\Database\Eloquent\Collection
    {
        if ($ids === []) {
            return FoodAlchemistSupplierItem::query()->whereRaw('1 = 0')->get();
        }

        return $this->baseQuery($team, [])->whereIn('id', $ids)->get();
    }

    private function baseQuery(Team $team, array $filters): Builder
    {
        return FoodAlchemistSupplierItem::visibleToTeam($team)
            ->with(['structure.gp:id,name,lead_la_supplier_item_id'])
            ->when($filters['onlyActive'] ?? true, fn ($q) => $q->where('is_discontinued', false))
            // Spec 16·S2 — WG-Lead-Scope: nur Artikel der übergebenen Lieferanten (whereIn).
            // Leeres/fehlendes Array = kein Scope (globale Suche, Ist-Verhalten unberührt).
            ->when(! empty($filters['supplier_ids']), fn ($q) => $q->whereIn('supplier_id', $filters['supplier_ids']))
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

    /**
     * Edit nur Besitzer-Team (D1); manuelle Pflege setzt source='manual' (GL-07-Lineage).
     *
     * `$source` ist die Lineage-Stempelung und bleibt für die UI auf `manual`. Der
     * Kanal-B-Datei-Import (Spec 13 · S1c) stempelt `datei`: NULL steht im Bestand für
     * den Necta-Bulk-Import, und beides gleich zu stempeln machte die Lineage genau dort
     * wertlos, wo sie gebraucht wird („welche Zeile kam aus einer gepflegten Datei?").
     *
     * **Voll-Ersatz-Semantik:** was nicht in `$werte` steht, wird `unbekannt`. Wer nur
     * einzelne Werte setzen will (Import), mischt vorher mit {@see getAllergens}.
     */
    public function setAllergens(Team $team, FoodAlchemistSupplierItem $item, array $werte, string $source = 'manual'): FoodAlchemistItemAllergen
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
            [...$attribute, 'team_id' => $item->team_id, 'source' => $source],
        );
    }

    // ── LA-Nährwerte (je 100 g) — speisen die GP-Aggregation (GL-08) ────

    /** Kern-Nährwerte fürs Item-Modal: Feld → [Label, Einheit] (je 100 g). */
    public const NAEHRWERT_FELDER = [
        'energy_kcal' => ['Energie', 'kcal'],
        'energy_kj' => ['Energie', 'kJ'],
        'protein' => ['Eiweiß', 'g'],
        'fat' => ['Fett', 'g'],
        'saturated_fat' => ['davon ges. Fettsäuren', 'g'],
        'carbs_absorbable' => ['Kohlenhydrate', 'g'],
        'sugar' => ['davon Zucker', 'g'],
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

    /**
     * Edit nur Besitzer-Team (D1). Leer ⇒ NULL; negativ/nicht-numerisch ⇒ NULL (kein stiller 0).
     * Voll-Ersatz **über die 8 Kernwerte** (die übrigen BLS-Spalten bleiben unangetastet);
     * wer nur einzelne setzen will (Import), mischt vorher mit {@see getNutrition}.
     */
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

    /**
     * Edit nur Besitzer-Team; manuelle Pflege stempelt source=manual. Schreibt ROHE Domäne (GL-09 A1).
     * `$source` wie bei {@see setAllergens} (Kanal B stempelt `datei`); Voll-Ersatz-Semantik ebenso.
     */
    public function setDeclarations(Team $team, FoodAlchemistSupplierItem $item, array $werte, string $source = 'manual'): FoodAlchemistItemDeclaration
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
            [...$attribute, 'team_id' => $item->team_id, 'source' => $source],
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
            'unit_code' => in_array($input['unit_code'] ?? '', self::UNIT_CODES, true) ? $input['unit_code'] : null,
        ]);
    }

    /** Editierbare Stammdaten-/Verpackungs-/Eigenschafts-Spalten des Lieferantenartikels (Whitelist). */
    public const EDIT_FELDER = [
        'designation', 'article_number', 'brand', 'manufacturer', 'origin', 'marketing_name', 'additional_text',
        'qty', 'unit_code', 'packaging_unit', 'ordering_unit', 'qty_ordering_per_packaging', 'ean_packaging', 'ean_ordering',
        'is_organic', 'is_vegan', 'is_vegetarian', 'is_alcohol', 'is_halal', 'is_gmo_free', 'is_preorder',
        'vat', 'origin_country', 'organic_control_number', 'preorder_days', 'ingredients_supplier',
    ];

    /**
     * Stammdaten eines bestehenden Artikels bearbeiten (MVP: vorher inline in ItemModal::speichern).
     * Nur Besitzer-Team; nur Whitelist-Spalten; leere Strings → null.
     */
    public function update(Team $team, FoodAlchemistSupplierItem $item, array $felder): FoodAlchemistSupplierItem
    {
        if (! $item->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Katalog-Artikel — Pflege nur durch das Besitzer-Team (D1).');
        }
        $clean = collect(array_intersect_key($felder, array_flip(self::EDIT_FELDER)))
            ->map(fn ($v) => $v === '' ? null : $v)->all();
        if ($clean !== []) {
            $item->update($clean);
        }

        return $item->refresh();
    }

    /** Deaktivieren = soft (is_discontinued), nie löschen — nur Besitzer-Team (D1). */
    public function setDiscontinued(Team $team, FoodAlchemistSupplierItem $item, bool $discontinued): void
    {
        if (! $item->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Katalog-Artikel — nur das Besitzer-Team darf (de)aktivieren (D1).');
        }
        $item->update(['is_discontinued' => $discontinued]);
    }

    /**
     * Löschen — MVP-013 (P0): nur das Besitzer-Team. Vorher löschte die Bulk-Leiste per
     * `visibleToTeam(...)->findOrFail()->delete()`, also mit dem LESE-Scope als Schreibrecht:
     * ein Kind-Team konnte geerbte Master-Katalog-Artikel löschen. Der Owner-Check ist die
     * Grenze, spiegelbildlich zu setDiscontinued().
     */
    public function loesche(Team $team, FoodAlchemistSupplierItem $item): void
    {
        if (! $item->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Katalog-Artikel — nur das Besitzer-Team darf löschen (D1).');
        }
        $item->delete();
    }

    /**
     * Guard für Schreibvorgänge, die den Artikel selbst nicht laden (Mapping-Umhängen, MVP-013):
     * lädt sichtbar und prüft Eigentum. Wirft `ModelNotFoundException` (nicht sichtbar) bzw.
     * `RuntimeException` (sichtbar, aber geerbt).
     */
    public function assertOwned(Team $team, int $itemId): FoodAlchemistSupplierItem
    {
        $item = FoodAlchemistSupplierItem::visibleToTeam($team)->findOrFail($itemId);
        if (! $item->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Katalog-Artikel — Mapping-Pflege nur durchs Besitzer-Team (D1).');
        }

        return $item;
    }
}
