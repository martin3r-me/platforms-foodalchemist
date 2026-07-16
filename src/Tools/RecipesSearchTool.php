<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\RecipeService;

/** M8-01: Basisrezepte durchsuchen (D-5, basis()-Scope). */
class RecipesSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Durchsucht die Basisrezepte (Produktion) des Teams; optional Status-Filter. Hybrid: '
            . 'lexikalisch (Name/Key) plus — bei aktivem Embedding-Provider — ein semantischer Pass '
            . '(Synonyme/Komposita, via: lexical|semantic je Treffer); ohne Provider rein lexikalisch. '
            . 'Liefert id, name, status, yield_kg, ek_total_eur — Details via foodalchemist.recipes.GET.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => ['stub', 'draft', 'review', 'approved', 'archived']],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10],
            ],
            'required' => ['q'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $q = (string) $arguments['q'];
        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 10)));
        $treffer = app(RecipeService::class)->paginateBrowser(
            ['search' => $q, 'status' => (string) ($arguments['status'] ?? '')], $team, $limit,
        );

        $recipes = collect($treffer->items())->map(fn ($r) => [
            'id' => $r->id, 'name' => $r->name, 'status' => $r->status->value,
            'yield_kg' => $r->yield_kg, 'ek_total_eur' => $r->ek_total_eur, 'via' => 'lexical',
        ])->all();

        // E4 (#507): semantische Ergänzung, auf Basisrezepte (D-5) gefiltert — kein VK.
        $semScores = $this->semanticPoolIds($team, $q, PoolEmbeddingService::ENTITY_TYPE_RECIPE, array_column($recipes, 'id'), $limit);
        if ($semScores !== []) {
            $rows = FoodAlchemistRecipe::visibleToTeam($team)->basis()
                ->whereIn('id', array_keys($semScores))
                ->get(['id', 'name', 'status', 'yield_kg', 'ek_total_eur'])->keyBy('id');
            arsort($semScores);
            foreach ($semScores as $id => $score) {
                $r = $rows->get($id);
                if ($r === null || count($recipes) >= $limit) {
                    continue;
                }
                $recipes[] = [
                    'id' => $r->id, 'name' => $r->name, 'status' => $r->status->value,
                    'yield_kg' => $r->yield_kg, 'ek_total_eur' => $r->ek_total_eur,
                    'via' => 'semantic', 'semantic_score' => round($score, 3),
                ];
            }
        }

        return ToolResult::success(['total' => count($recipes), 'recipes' => $recipes]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'recipe', 'rezept', 'basisrezept', 'search'],
            'examples' => ['Suche Rezepte mit Sauce Hollandaise'],
        ];
    }
}
