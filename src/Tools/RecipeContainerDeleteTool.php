<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabContainer;
use Platform\FoodAlchemist\Services\SalesRecipeService;

/** Spec 51 · MCP: Behälter-Zeile eines Zwecks an einem team-eigenen Rezept entfernen. */
class RecipeContainerDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_container.DELETE';
    }

    public function getDescription(): string
    {
        return 'Entfernt den Behälter für einen Zweck an einem Rezept. Danach fällt das Rezept auf den '
            . 'Zustand »nichts hinterlegt« zurück — der Bedarf wird dann nicht gerechnet, sondern gemeldet.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Rezept-Id (team-eigen).'],
                'zweck' => ['type' => 'string', 'enum' => FoodAlchemistVocabContainer::ZWECKE],
            ],
            'required' => ['recipe_id', 'zweck'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $zweck = (string) ($arguments['zweck'] ?? '');
        if (! in_array($zweck, FoodAlchemistVocabContainer::ZWECKE, true)) {
            return ToolResult::error('zweck muss abfuellen|regenerieren|ausgabe|transport sein.', 'VALIDATION_ERROR');
        }
        $recipeId = (int) ($arguments['recipe_id'] ?? 0);
        if (($guard = $this->guardRecipe($team, $recipeId)) !== null) {
            return $guard;
        }

        app(SalesRecipeService::class)->deleteContainer($team, $recipeId, $zweck);

        return ToolResult::success(['recipe_id' => $recipeId, 'zweck' => $zweck, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'rezept', 'behaelter', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.recipe_container.PUT'],
            'examples' => ['Entferne am Rezept 812 den Ausgabe-Behälter.'],
        ];
    }
}
