<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SalesImportService;

/** MCP-Steuerbarkeit · D12 (Controlling): einen Verkaufs-Fakt einem Rezept zuordnen/lösen. */
class SalesFactsMapTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.sales_facts.MAP';
    }

    public function getDescription(): string
    {
        return 'Ordnet einen team-eigenen Verkaufs-Fakt einem sichtbaren Rezept zu (recipe_id) oder löst die Zuordnung (recipe_id weglassen).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fact_id' => ['type' => 'integer', 'description' => 'Verkaufs-Fakt-Id.'],
                'recipe_id' => ['type' => 'integer', 'description' => 'Rezept-Id (weglassen = Zuordnung lösen).'],
            ],
            'required' => ['fact_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $factId = (int) ($arguments['fact_id'] ?? 0);
        $recipeId = isset($arguments['recipe_id']) ? (int) $arguments['recipe_id'] : null;

        $ok = app(SalesImportService::class)->zuordnen($team, $factId, $recipeId);
        if (! $ok) {
            return ToolResult::error('Fakt nicht gefunden oder Rezept nicht sichtbar.', 'NOT_FOUND');
        }

        return ToolResult::success(['fact_id' => $factId, 'recipe_id' => $recipeId, 'mapped' => $recipeId !== null]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'controlling', 'sales', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.sales_facts.GET'],
            'examples' => ['Ordne den Verkaufs-Fakt 88 dem Rezept 12 zu.'],
        ];
    }
}
