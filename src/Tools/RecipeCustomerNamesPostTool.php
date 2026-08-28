<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SalesRecipeService;

/**
 * MCP-Steuerbarkeit · D3: Kundenspezifischen Marketing-Namen an einem team-eigenen Gericht hinterlegen
 * (dasselbe Gericht heißt bei Kunde A anders als bei Kunde B).
 */
class RecipeCustomerNamesPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_customer_names.POST';
    }

    public function getDescription(): string
    {
        return 'Hinterlegt einen kundenspezifischen Marketing-Namen an einem team-eigenen Gericht (kunde, marketing_name, note?).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Gericht-Id (team-eigen).'],
                'kunde' => ['type' => 'string', 'description' => 'Kunden-Bezeichnung.'],
                'marketing_name' => ['type' => 'string', 'description' => 'Name des Gerichts bei diesem Kunden.'],
                'note' => ['type' => 'string', 'description' => 'Notiz (optional).'],
            ],
            'required' => ['recipe_id', 'kunde', 'marketing_name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $kunde = trim((string) ($arguments['kunde'] ?? ''));
        $name = trim((string) ($arguments['marketing_name'] ?? ''));
        if ($kunde === '' || $name === '') {
            return ToolResult::error('kunde und marketing_name sind Pflicht.', 'VALIDATION_ERROR');
        }

        $recipeId = (int) ($arguments['recipe_id'] ?? 0);
        if (($guard = $this->guardVkRecipe($team, $recipeId)) !== null) {
            return $guard;
        }

        try {
            app(SalesRecipeService::class)->addCustomerName($team, $recipeId, $kunde, $name, isset($arguments['note']) ? (string) $arguments['note'] : null);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['recipe_id' => $recipeId, 'kunde' => $kunde, 'marketing_name' => $name]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gericht', 'kundenname', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.recipe_customer_names.DELETE'],
            'examples' => ['Nenne Gericht 501 bei Kunde „Adler" „Fjord-Lachs".'],
        ];
    }
}
