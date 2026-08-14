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

        // Gestuft (Gate pro Ebene): die Sub-Rezepte NICHT sofort erzeugen, sondern die Kandidaten am Step
        // aufbewahren — die Freigabe dieses Steps arbeitet sie ab ({@see resumeDeferredChildren}).
        if ($parameter['_defer_children'] ?? false) {
            $step->update(['deferred' => ['children' => [
                'offene' => array_values($offene),
                'params' => $parameter,
                'user_id' => $userId,
            ]]]);

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

    /** Erzeugt je offener `basisrezept_anlegen`-Zeile einen Kind-Step + {@see GenerateRecipeJob} (eager Dispatch-Kern). */
    private function dispatchChildren(Team $team, FoodAlchemistCascadeRunStep $step, int $userId, FoodAlchemistRecipe $recipe, array $offene, array $parameter): void
    {
        if ((int) $step->depth >= self::MAX_DEPTH) {
            return;
        }
        $kindVollAnreichern = (bool) ($parameter['_voll_anreichern'] ?? false);
        $childParameter = $parameter;
        unset($childParameter['_voll_anreichern'], $childParameter['_defer_children']);

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
            if (FoodAlchemistCascadeRunStep::where('cascade_run_id', $step->cascade_run_id)->count() >= self::MAX_STEPS) {
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

            [$child, $created] = DB::transaction(function () use ($team, $step, $ingredient, $text, $dedupe) {
                $existing = FoodAlchemistCascadeRunStep::where('cascade_run_id', $step->cascade_run_id)
                    ->where('dedupe_key', $dedupe)->lockForUpdate()->first();
                if ($existing !== null) {
                    return [$existing, false];
                }
                return [FoodAlchemistCascadeRunStep::create([
                    'team_id' => $team->id,
                    'cascade_run_id' => $step->cascade_run_id,
                    'parent_step_id' => $step->id,
                    'depth' => ((int) $step->depth) + 1,
                    'kind' => 'rezept',
                    'label' => Str::limit($text, 120),
                    'dedupe_key' => $dedupe,
                    'status' => 'running',
                    'sort' => (int) $ingredient->position,
                ]), true];
            });

            FoodAlchemistCascadeRecipeDependency::firstOrCreate([
                'team_id' => $team->id,
                'cascade_run_id' => $step->cascade_run_id,
                'parent_step_id' => $step->id,
                'ingredient_id' => $ingredient->id,
            ], ['child_step_id' => $child->id]);

            if ($child->status === 'done' && $child->ref_id !== null) {
                $this->bindIngredient($team, (int) $ingredient->id, (int) $child->ref_id);
            } elseif ($created) {
                $runId = (string) Str::uuid();
                $child->update(['generator_run_id' => $runId]);
                Cache::put(GenerateRecipeJob::cacheKey($runId), ['status' => 'pending'], now()->addMinutes(60));
                GenerateRecipeJob::dispatch($runId, $team->id, $userId, $text, [
                    ...$childParameter,
                    'cascade_step_id' => $child->id,
                    'auto_dependencies' => true,
                ], false, $kindVollAnreichern);
            }
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
