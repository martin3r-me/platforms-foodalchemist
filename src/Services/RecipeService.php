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
