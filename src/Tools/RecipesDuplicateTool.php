<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * MCP-Steuerbarkeit · D2: Basisrezept duplizieren (inkl. Zutaten). Die Kopie gehört dem eigenen Team;
 * darum genügt Sichtbarkeit der Vorlage (auch geerbte/globale Basisrezepte dürfen kopiert werden).
 */
class RecipesDuplicateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.DUPLICATE';
    }

    public function getDescription(): string
    {
        return 'Dupliziert ein sichtbares Basisrezept (mit Zutaten) unter neuem Namen; die Kopie gehört dem eigenen Team.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Vorlage-Basisrezept-Id (sichtbar).'],
                'name' => ['type' => 'string', 'description' => 'Name der Kopie.'],
            ],
            'required' => ['id', 'name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $name = trim((string) ($arguments['name'] ?? ''));
        if ($name === '') {
            return ToolResult::error('name ist Pflicht.', 'VALIDATION_ERROR');
        }

        $id = (int) ($arguments['id'] ?? 0);
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', false)->whereKey($id)->first();
        if ($recipe === null) {
            return ToolResult::error('Basisrezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        $kopie = app(RecipeService::class)->duplicate($team, $id, $name);

        return ToolResult::success([
            'id' => (int) $kopie->id,
            'name' => $kopie->name,
            'status' => $this->statusWert($kopie),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'recipe', 'basisrezept', 'duplizieren', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.recipes.GET'],
            'examples' => ['Dupliziere Basisrezept 12 als „Fond: Kalb (Variante)".'],
        ];
    }
}
