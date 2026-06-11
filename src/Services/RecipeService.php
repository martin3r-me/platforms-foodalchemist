<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeMainGroup;

/**
 * M4-04/05 / D-5 §3.1 (Listen-Teil): Basisrezept-Browser — Scope basis() wird
 * in JEDER Query erzwungen (D-6/SalesRecipeService bekommt seine eigene Sicht).
 * CRUD/Zutaten-Mutationen folgen mit M4-06/07 (jede Mutation → recomputeAndPropagate).
 */
class RecipeService
{
    /** Hauptgruppen-Zähler in einer GROUP-BY-Query (Baum links, P-1). */
    public function hauptgruppenCounts(Team $team, array $filters = []): array
    {
        return $this->browserQuery($team, array_diff_key($filters, ['hauptgruppe' => 1, 'kategorie' => 1]))
            ->join('foodalchemist_recipe_categories AS k', 'k.id', '=', 'foodalchemist_recipes.kategorie_id')
            ->selectRaw('k.main_group_id, COUNT(*) AS n')
            ->groupBy('k.main_group_id')
            ->pluck('n', 'k.main_group_id')
            ->all();
    }

    /** Kategorie-Zähler der gewählten Hauptgruppe (zweite Baum-Ebene). */
    public function kategorieCounts(Team $team, int $mainGroupId): array
    {
        return $this->browserQuery($team, [])
            ->join('foodalchemist_recipe_categories AS k', 'k.id', '=', 'foodalchemist_recipes.kategorie_id')
            ->where('k.main_group_id', $mainGroupId)
            ->selectRaw('foodalchemist_recipes.kategorie_id, COUNT(*) AS n')
            ->groupBy('foodalchemist_recipes.kategorie_id')
            ->pluck('n', 'foodalchemist_recipes.kategorie_id')
            ->all();
    }

    public function mainGroups(Team $team): Collection
    {
        return FoodAlchemistRecipeMainGroup::visibleToTeam($team)
            ->orderBy('sort_order')->orderBy('code')->get();
    }

    /** Tabelle (M4-04): Name·HG·Kategorie·Geschmack·Fertigung·Status·Zutaten·Yield·Allergen-Konf. */
    public function paginateBrowser(array $filters, Team $team, int $perPage = 100): LengthAwarePaginator
    {
        return $this->browserQuery($team, $filters)
            ->with('kategorie:id,main_group_id,bezeichnung')
            ->orderBy('foodalchemist_recipes.name')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Panel (M4-05): Detail inkl. Zutaten (GP-Links), Eignungen, Equipment. */
    public function detail(Team $team, int $id): ?FoodAlchemistRecipe
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->with([
                'kategorie:id,main_group_id,bezeichnung',
                'ingredients.gp:id,name,hauptzutat_slug,lead_la_supplier_item_id,stk_default_g', // Zeilen-EK braucht Lead+Stückgewicht (T3)
                'ingredients.einheit:id,slug,display_de,dimension,default_in_g,default_in_ml', // T1-Kaskade braucht die Faktoren
                'ingredients.referencedRecipe:id,name,ek_per_kg_eur',
                'equipment',
                'niveauEignungen',
                'sektorEignungen',
            ])
            ->find($id);
    }

    public function statusCounts(Team $team): array
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->all();
    }

    // ── M4-06: CRUD (D-5 §3.1) ──────────────────────────────────────────

    /** Regelwerk §1.7: slug(name) — ä→ae/ö→oe/ü→ue/ß→ss, Sonderzeichen→_, kollabiert. */
    public function rezeptKey(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '_', $s);

        return trim(preg_replace('/_+/', '_', $s), '_');
    }

    /**
     * Anlage (status draft, key nach §1.7 + §1.8-Diskriminator: bei Kollision
     * +_slug(kategorie), dann _2/_3 …). Läuft die Pipeline einmal an (leere Aggregate).
     */
    public function create(Team $team, array $in): FoodAlchemistRecipe
    {
        $name = trim($in['name'] ?? '');
        if ($name === '') {
            throw new \RuntimeException('Rezept-Name ist Pflicht (§1).');
        }
        $kategorieId = $in['kategorie_id'] ?? null;

        $key = $this->rezeptKey($name);
        if ($this->keyVergeben($team, $key)) {                     // §1.8: Kategorie als Diskriminator
            $kategorie = $kategorieId !== null
                ? \Platform\FoodAlchemist\Models\FoodAlchemistRecipeCategory::find($kategorieId)?->bezeichnung
                : null;
            if ($kategorie !== null) {
                $key = $this->rezeptKey($name) . '_' . $this->rezeptKey($kategorie);
            }
            $basis = $key;
            for ($n = 2; $this->keyVergeben($team, $key); $n++) {  // identische Duplikate: _2-Suffix
                $key = "{$basis}_{$n}";
            }
        }

        $recipe = FoodAlchemistRecipe::create([
            'team_id' => $team->id,
            'recipe_key' => $key,
            'name' => $name,
            'herkunft' => ($in['herkunft'] ?? '') ?: null,
            'kategorie_id' => $kategorieId,
            'ist_verkaufsrezept' => (bool) ($in['ist_verkaufsrezept'] ?? false),
            'status' => 'draft',
            'geschmacksrichtung' => ($in['geschmacksrichtung'] ?? '') ?: null,
            'fertigungstiefe' => ($in['fertigungstiefe'] ?? '') ?: null,
            'arbeitszeit_min' => $in['arbeitszeit_min'] ?? null,
            'yield_kg_manual' => $in['yield_kg_manual'] ?? null,
            'beschreibung' => ($in['beschreibung'] ?? '') ?: null,
            'last_modified_by' => 'editor',
        ]);
        app(RecipeRecomputeService::class)->recomputePipeline($recipe->id);

        return $recipe->refresh();
    }

    /** Edit: recipe_key bleibt STABIL (Referenzen/Slugs); Recompute bei kalkulations-relevanten Feldern. */
    public function update(Team $team, int $id, array $in): FoodAlchemistRecipe
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($id);
        if ((int) $recipe->team_id !== (int) $team->id) {
            throw new \RuntimeException('Geerbtes Rezept — Pflege nur durchs Besitzer-Team (D1).');
        }
        $name = trim($in['name'] ?? $recipe->name);
        if ($name === '') {
            throw new \RuntimeException('Rezept-Name ist Pflicht (§1).');
        }

        $altManual = $recipe->yield_kg_manual;
        $recipe->update([
            'name' => $name,
            'herkunft' => array_key_exists('herkunft', $in) ? (($in['herkunft'] ?? '') ?: null) : $recipe->herkunft,
            'kategorie_id' => $in['kategorie_id'] ?? $recipe->kategorie_id,
            'geschmacksrichtung' => array_key_exists('geschmacksrichtung', $in) ? (($in['geschmacksrichtung'] ?? '') ?: null) : $recipe->geschmacksrichtung,
            'fertigungstiefe' => array_key_exists('fertigungstiefe', $in) ? (($in['fertigungstiefe'] ?? '') ?: null) : $recipe->fertigungstiefe,
            'arbeitszeit_min' => array_key_exists('arbeitszeit_min', $in) ? $in['arbeitszeit_min'] : $recipe->arbeitszeit_min,
            'yield_kg_manual' => array_key_exists('yield_kg_manual', $in) ? $in['yield_kg_manual'] : $recipe->yield_kg_manual,
            'beschreibung' => array_key_exists('beschreibung', $in) ? (($in['beschreibung'] ?? '') ?: null) : $recipe->beschreibung,
            'version' => $recipe->version + 1,
            'last_modified_by' => 'editor',
        ]);
        if (array_key_exists('yield_kg_manual', $in) && $in['yield_kg_manual'] !== $altManual) {
            app(RecipeRecomputeService::class)->recomputeAndPropagate($recipe->id); // ek/kg-Nenner (A-3)
        }

        return $recipe->refresh();
    }

    /** Kopie inkl. Zutaten, status draft (D-5 §3.1). */
    public function duplicate(Team $team, int $id, string $neuerName): FoodAlchemistRecipe
    {
        $original = FoodAlchemistRecipe::visibleToTeam($team)->with('ingredients')->findOrFail($id);

        return DB::transaction(function () use ($team, $original, $neuerName) {
            $kopie = $this->create($team, [
                'name' => $neuerName,
                'kategorie_id' => $original->kategorie_id,
                'herkunft' => $original->herkunft,
                'geschmacksrichtung' => $original->geschmacksrichtung,
                'fertigungstiefe' => $original->fertigungstiefe,
                'ist_verkaufsrezept' => $original->ist_verkaufsrezept,
                'beschreibung' => $original->beschreibung,
            ]);
            foreach ($original->ingredients as $z) {
                $kopie->ingredients()->create([
                    ...$z->only(['position', 'gp_id', 'referenced_recipe_id', 'raw_text', 'display_name',
                        'menge', 'menge_max', 'einheit_vocab_id', 'putzverlust_pct', 'garverlust_pct',
                        'is_optional', 'klammer_note', 'note', 'match_method', 'match_confidence',
                        'rolle', 'ist_wertgebend', 'rechen_modus']),
                    'team_id' => $team->id,
                ]);
            }
            app(RecipeRecomputeService::class)->recomputePipeline($kopie->id);

            return $kopie->refresh();
        });
    }

    /** Löschen blockt, wenn Eltern dieses Rezept als Sub referenzieren (typisierte Exception, V-06). */
    public function delete(Team $team, int $id): void
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($id);
        if ((int) $recipe->team_id !== (int) $team->id) {
            throw new \RuntimeException('Geerbtes Rezept — Löschen nur durchs Besitzer-Team (D1).');
        }
        $eltern = $recipe->parentIngredients()->whereNull('deleted_at')->with('recipe:id,name')->get();
        if ($eltern->isNotEmpty()) {
            throw new \RuntimeException('Rezept wird als Sub-Rezept referenziert von: '
                . $eltern->pluck('recipe.name')->unique()->implode(', ') . ' — erst dort lösen.');
        }
        DB::transaction(function () use ($recipe) {
            $recipe->ingredients()->delete();
            $recipe->delete();
        });
    }

    public function setStatus(Team $team, int $id, string $status): FoodAlchemistRecipe
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($id);
        if (\Platform\FoodAlchemist\Enums\RecipeStatus::tryFrom($status) === null) {
            throw new \RuntimeException("Unbekannter Status [{$status}].");
        }
        $recipe->update(['status' => $status, 'last_modified_by' => 'editor']);

        return $recipe->refresh();
    }

    private function keyVergeben(Team $team, string $key): bool
    {
        return FoodAlchemistRecipe::where('team_id', $team->id)->where('recipe_key', $key)->exists();
    }

    private function browserQuery(Team $team, array $filters): Builder
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->basis()
            ->when(($filters['search'] ?? '') !== '', function (Builder $q) use ($filters) {
                $such = mb_strtolower(trim($filters['search']));
                $q->where(fn (Builder $w) => $w
                    ->whereRaw('LOWER(foodalchemist_recipes.name) LIKE ?', ['%' . $such . '%'])
                    ->orWhereRaw('LOWER(foodalchemist_recipes.recipe_key) LIKE ?', ['%' . $such . '%']));
            })
            ->when(($filters['hauptgruppe'] ?? null) !== null && $filters['hauptgruppe'] !== '', fn (Builder $q) => $q
                ->whereIn('kategorie_id', DB::table('foodalchemist_recipe_categories')
                    ->where('main_group_id', (int) $filters['hauptgruppe'])->pluck('id')))
            ->when(($filters['kategorie'] ?? null) !== null && $filters['kategorie'] !== '', fn (Builder $q) => $q
                ->where('kategorie_id', (int) $filters['kategorie']))
            ->when(($filters['status'] ?? '') !== '', fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(($filters['geschmack'] ?? '') !== '', fn (Builder $q) => $q->where('geschmacksrichtung', $filters['geschmack']))
            ->when(($filters['fertigung'] ?? '') !== '', fn (Builder $q) => $q->where('fertigungstiefe', $filters['fertigung']));
    }
}
