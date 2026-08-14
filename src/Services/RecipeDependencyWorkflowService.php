<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRecipeDependency;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/** Persistenter, begrenzter DAG für ineinander verschachtelte Basisrezepte. */
class RecipeDependencyWorkflowService
{
    public const MAX_DEPTH = 3;
    public const MAX_STEPS = 50;

    public function prepare(Team $team, int $stepId, string $description, array $parameter, bool $vkModus): array
    {
        $context = app(RecipeGenerationContextService::class)->build($team, $description, $parameter, $vkModus);
        FoodAlchemistCascadeRunStep::whereKey($stepId)->update(['context_snapshot' => $context['snapshot']]);

        return $context;
    }

    public function afterGenerated(Team $team, int $stepId, int $userId, FoodAlchemistRecipe $recipe, array $offene, array $parameter): void
    {
        $step = FoodAlchemistCascadeRunStep::find($stepId);
        if ($step === null) {
            return;
        }

        $this->bindCompletedChild($team, $step, $recipe);

        // Sichtbarkeit (Beobachtung Dominique 2026-08-14): die vom Generator direkt verdrahteten
        // Sub-Rezepte gehören in die Basisrezepte-Stufe, nicht nur als 📖-Referenz in die Zutatenliste.
        $this->spiegleReuseKinder($team, $step, $recipe);

        // Gestuft (Gate pro Ebene): die Sub-Rezepte NICHT sofort erzeugen, sondern die Kandidaten am Step
        // aufbewahren — die Freigabe dieses Steps arbeitet sie ab ({@see resumeDeferredChildren}).
        if ($parameter['_defer_children'] ?? false) {
            $step->update(['deferred' => ['children' => [
                'offene' => array_values($offene),
                'params' => $parameter,
                'user_id' => $userId,
            ]]]);
            // …aber sie werden SOFORT sichtbar: je Kandidat ein `geplant`-Step in der Basisrezepte-
            // Stufe (Gericht = Basisrezepte, nicht flache Zutaten). Kein Job — die Freigabe der Stufe
            // darüber schaltet sie scharf ({@see resumeDeferredChildren}).
            $this->planChildren($team, $step, $recipe, $offene, $parameter);

            return;
        }

        if (! ($parameter['auto_dependencies'] ?? false) || (int) $step->depth >= self::MAX_DEPTH) {
            return;
        }
        $this->dispatchChildren($team, $step, $userId, $recipe, $offene, $parameter);
    }

    /**
     * Fortsetzung eines aufgeschobenen Steps bei der Freigabe: die vorgemerkten Sub-Rezepte jetzt erzeugen.
     * Ab hier eager — die freigegebene Ebene erzeugt ihre Kinder; tiefere Ebenen lösen sich automatisch auf.
     */
    public function resumeDeferredChildren(Team $team, FoodAlchemistCascadeRunStep $step, FoodAlchemistRecipe $recipe): void
    {
        $d = $step->deferred['children'] ?? null;
        if (! is_array($d)) {
            return;
        }
        $params = is_array($d['params'] ?? null) ? $d['params'] : [];
        $params['auto_dependencies'] = true;
        unset($params['_defer_children']);
        $offene = is_array($d['offene'] ?? null) ? $d['offene'] : [];
        $this->dispatchChildren($team, $step, (int) ($d['user_id'] ?? 0), $recipe, $offene, $params);
        $step->update(['deferred' => null]);
    }

    /**
     * Schaltet die geplanten Sub-Rezepte EINES Steps scharf: {@see planChildren} legt/findet die
     * Kind-Steps, dieser Dispatch-Kern startet je noch nicht laufendem Kind einen
     * {@see GenerateRecipeJob} (`geplant` → `running`). Ein im Lauf geteiltes Sub-Rezept wird nur
     * EINMAL erzeugt und danach an alle Eltern-Zutaten gebunden.
     */
    private function dispatchChildren(Team $team, FoodAlchemistCascadeRunStep $step, int $userId, FoodAlchemistRecipe $recipe, array $offene, array $parameter): void
    {
        $kindVollAnreichern = (bool) ($parameter['_voll_anreichern'] ?? false);
        $childParameter = $parameter;
        unset($childParameter['_voll_anreichern'], $childParameter['_defer_children']);

        foreach ($this->planChildren($team, $step, $recipe, $offene, $parameter) as [$child, $ingredientId, $text]) {
            if ($child->status === 'done' && $child->ref_id !== null) {
                $this->bindIngredient($team, $ingredientId, (int) $child->ref_id);

                continue;
            }
            if ($child->status !== 'geplant') {
                continue;   // schon unterwegs (running/queued) oder terminal — kein zweiter Job
            }
            $runId = (string) Str::uuid();
            $child->update(['status' => 'running', 'generator_run_id' => $runId]);
            Cache::put(GenerateRecipeJob::cacheKey($runId), ['status' => 'pending'], now()->addMinutes(60));
            GenerateRecipeJob::dispatch($runId, $team->id, $userId, $text, [
                ...$childParameter,
                'cascade_step_id' => $child->id,
                'auto_dependencies' => true,
            ], false, $kindVollAnreichern);
        }
    }

    /**
     * Schaltet EINEN geplanten Sub-Rezept-Step scharf — der „jetzt erzeugen"-Knopf je Zeile, VOR der
     * Freigabe der Stufe darüber. Nutzt die am Eltern-Step aufgeschobenen Kind-Parameter
     * ({@see afterGenerated}: `deferred.children.params`/`user_id`), fällt sonst auf die Lauf-Params
     * zurück. Dispatcht genau EINEN {@see GenerateRecipeJob} (`geplant` → `running`). Die spätere
     * Stufen-Freigabe ({@see dispatchChildren}) sieht den Step dann nicht mehr als `geplant` und
     * startet ihn nicht doppelt. Kein Re-Planen: die Zeile + ihre Dependency stehen schon.
     *
     * @return bool true, wenn ein Job dispatcht wurde (Aufrufer recomputet den Run danach)
     */
    public function dispatchGeplantesKind(Team $team, FoodAlchemistCascadeRunStep $child): bool
    {
        if ($child->status !== 'geplant' || $child->kind !== 'rezept') {
            return false;
        }
        $text = trim((string) $child->label);
        if ($text === '') {
            return false;
        }
        $parent = $child->parent_step_id !== null ? FoodAlchemistCascadeRunStep::find($child->parent_step_id) : null;
        $d = is_array($parent?->deferred['children'] ?? null) ? $parent->deferred['children'] : [];
        $params = is_array($d['params'] ?? null) ? $d['params'] : (is_array($child->run?->params) ? $child->run->params : []);
        $userId = (int) ($d['user_id'] ?? \Illuminate\Support\Facades\Auth::id() ?? 0);
        $kindVollAnreichern = (bool) ($params['_voll_anreichern'] ?? false);
        unset($params['_voll_anreichern'], $params['_defer_children']);

        $runId = (string) Str::uuid();
        $child->update(['status' => 'running', 'generator_run_id' => $runId]);
        Cache::put(GenerateRecipeJob::cacheKey($runId), ['status' => 'pending'], now()->addMinutes(60));
        GenerateRecipeJob::dispatch($runId, $team->id, $userId, $text, [
            ...$params,
            'cascade_step_id' => $child->id,
            'auto_dependencies' => true,
        ], false, $kindVollAnreichern);

        return true;
    }

    /**
     * Plant die Sub-Rezepte eines Steps: je offener `basisrezept_anlegen`-Zeile ein Kind-Step
     * (`kind=rezept`, Status `geplant` = benannt, noch nicht erzeugt) + die Dependency auf die
     * Eltern-Zutat. Legt KEINE Jobs an — das ist {@see dispatchChildren}. Idempotent über
     * `dedupe_key` (identische Sub-Rezepte teilen sich EINEN Step im Lauf); gedeckelt durch
     * {@see MAX_DEPTH}/{@see MAX_STEPS}, wobei `skipped`-Zeilen (reine Reuse-Sichtbarkeit) das
     * Erzeugungs-Budget NICHT verbrauchen.
     *
     * @return list<array{0: FoodAlchemistCascadeRunStep, 1: int, 2: string}> je Kandidat
     *                                                                       [Kind-Step, Zutat-ID, Kandidaten-Text (= Brief der Erzeugung)]
     */
    private function planChildren(Team $team, FoodAlchemistCascadeRunStep $step, FoodAlchemistRecipe $recipe, array $offene, array $parameter): array
    {
        if ((int) $step->depth >= self::MAX_DEPTH) {
            return [];
        }
        $geplant = [];

        foreach ($offene as $open) {
            // Kohärenz-Gate (2026-08-07): ENTdrahtete Fremdkörper-Zeilen tragen einen `kritiker`-
            // Grund. Sie dürfen NICHT auto-nachgeneriert werden — sonst liesse die Kaskade den
            // gerade als unpassend entfernten Fremdkörper als frisches Sub-Rezept wiederauferstehen.
            if (isset($open['kritiker'])) {
                continue;
            }
            if (($open['primaer'] ?? null) !== 'basisrezept_anlegen') {
                continue;
            }
            if (FoodAlchemistCascadeRunStep::where('cascade_run_id', $step->cascade_run_id)
                ->where('status', '!=', 'skipped')->count() >= self::MAX_STEPS) {
                break;
            }
            $ingredient = $recipe->ingredients()->where('position', ((int) ($open['index'] ?? 0)) + 1)->first();
            if ($ingredient === null || $ingredient->referenced_recipe_id !== null) {
                continue;
            }
            $text = trim((string) ($open['text'] ?? $ingredient->display_name ?? $ingredient->raw_text));
            if ($text === '') {
                continue;
            }
            $dedupe = hash('sha256', mb_strtolower($text) . '|' . json_encode([
                $parameter['convenience'] ?? null, $parameter['frische'] ?? null,
                $parameter['bio'] ?? null, $parameter['niveau'] ?? null,
            ]));

            $child = DB::transaction(function () use ($team, $step, $ingredient, $text, $dedupe) {
                $existing = FoodAlchemistCascadeRunStep::where('cascade_run_id', $step->cascade_run_id)
                    ->where('dedupe_key', $dedupe)->lockForUpdate()->first();
                if ($existing !== null) {
                    return $existing;
                }

                return FoodAlchemistCascadeRunStep::create([
                    'team_id' => $team->id,
                    'cascade_run_id' => $step->cascade_run_id,
                    'parent_step_id' => $step->id,
                    'depth' => ((int) $step->depth) + 1,
                    'kind' => 'rezept',
                    'label' => Str::limit($text, 120),
                    'dedupe_key' => $dedupe,
                    'status' => 'geplant',
                    'sort' => (int) $ingredient->position,
                ]);
            });

            FoodAlchemistCascadeRecipeDependency::firstOrCreate([
                'team_id' => $team->id,
                'cascade_run_id' => $step->cascade_run_id,
                'parent_step_id' => $step->id,
                'ingredient_id' => $ingredient->id,
            ], ['child_step_id' => $child->id]);

            $geplant[] = [$child, (int) $ingredient->id, $text];
        }

        return $geplant;
    }

    /**
     * Reuse-Sichtbarkeit (Beobachtung Dominique 2026-08-14): die vom Generator DIREKT verdrahteten
     * Sub-Rezepte (die 📖-Referenzen in der Zutatenliste) erscheinen als eigene Zeile der
     * Basisrezepte-Stufe — Status `skipped` (Reuse-Treffer: nichts zu erzeugen, nur zu prüfen), mit
     * Sprung aufs echte Rezept. Rein informativ: kein Job, keine Dependency, und das referenzierte
     * Bestands-Rezept wird NIE angetastet. Fail-open — eine Sicht-Zeile darf keine Generierung kippen.
     */
    private function spiegleReuseKinder(Team $team, FoodAlchemistCascadeRunStep $step, FoodAlchemistRecipe $recipe): void
    {
        if (! in_array($step->kind, ['gericht', 'rezept'], true)) {
            return;
        }
        try {
            $zeilen = $recipe->ingredients()->whereNotNull('referenced_recipe_id')
                ->with('referencedRecipe:id,name')->orderBy('position')->get();
            foreach ($zeilen as $z) {
                if ((int) $z->referenced_recipe_id === (int) $recipe->id) {
                    continue;   // Selbstbezug kann nie eine eigene Stufe sein
                }
                FoodAlchemistCascadeRunStep::firstOrCreate([
                    'cascade_run_id' => $step->cascade_run_id,
                    'dedupe_key' => 'reuse:' . (int) $z->referenced_recipe_id,
                ], [
                    'team_id' => $team->id,
                    'parent_step_id' => $step->id,
                    'depth' => ((int) $step->depth) + 1,
                    'kind' => 'rezept',
                    'label' => Str::limit((string) ($z->referencedRecipe?->name ?: ($z->display_name ?: $z->raw_text)), 120),
                    'status' => 'skipped',
                    'ref_type' => 'recipe',
                    'ref_id' => (int) $z->referenced_recipe_id,
                    'sort' => (int) $z->position,
                ]);
            }
        } catch (\Throwable) {
            // Parallel angelegt (dedupe-Unique) oder Zeile weg — Sichtbarkeit ist kein Blocker.
        }
    }

    private function bindCompletedChild(Team $team, FoodAlchemistCascadeRunStep $child, FoodAlchemistRecipe $recipe): void
    {
        FoodAlchemistCascadeRecipeDependency::where('child_step_id', $child->id)->get()
            ->each(fn ($dependency) => $this->bindIngredient($team, (int) $dependency->ingredient_id, (int) $recipe->id));
    }

    private function bindIngredient(Team $team, int $ingredientId, int $recipeId): void
    {
        $ingredient = \Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient::find($ingredientId);
        if ($ingredient === null || $ingredient->gp_id !== null || $ingredient->referenced_recipe_id !== null) {
            return;
        }
        if (! app(RecipeRecomputeService::class)->pruefeVerknuepfung((int) $ingredient->recipe_id, $recipeId)['erlaubt']) {
            return;
        }
        $ingredient->update([
            'referenced_recipe_id' => $recipeId,
            'match_method' => 'recipe_ref',
            'match_confidence' => null,
        ]);
        app(RecipeRecomputeService::class)->recomputeAndPropagate((int) $ingredient->recipe_id);
    }
}
