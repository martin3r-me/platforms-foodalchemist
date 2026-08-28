<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * MCP-Steuerbarkeit · D2: Template-Flag eines team-eigenen Basisrezepts setzen/togglen
 * (Vorlagen für die Instanziierung). Ohne is_template wird umgeschaltet.
 */
class RecipesTemplateToggleTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.TEMPLATE_TOGGLE';
    }

    public function getDescription(): string
    {
        return 'Setzt/toggelt das Template-Flag eines team-eigenen Basisrezepts (Vorlage für Instanziierung).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Basisrezept-Id (team-eigen).'],
                'is_template' => ['type' => 'boolean', 'description' => 'Zielwert; weggelassen = umschalten.'],
            ],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $id = (int) ($arguments['id'] ?? 0);
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', false)->whereKey($id)->first();
        if ($recipe === null) {
            return ToolResult::error('Basisrezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $recipe->isOwnedBy($team)) {
            return ToolResult::error('Geerbtes Rezept — Pflege nur durchs Besitzer-Team.', 'ACCESS_DENIED');
        }

        $ziel = array_key_exists('is_template', $arguments) ? (bool) $arguments['is_template'] : null;
        $recipe = app(RecipeService::class)->setTemplate($team, $id, $ziel);

        return ToolResult::success(['id' => (int) $recipe->id, 'is_template' => (bool) $recipe->is_template]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'recipe', 'basisrezept', 'template', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipes.GET'],
            'examples' => ['Markiere Basisrezept 12 als Vorlage.'],
        ];
    }
}
