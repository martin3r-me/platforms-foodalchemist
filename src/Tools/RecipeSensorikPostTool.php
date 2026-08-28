<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\SensorikService;

/**
 * MCP-Steuerbarkeit · D2: Sensorik eines team-eigenen Rezepts (KI) bewerten/ableiten und persistieren.
 * Der Service scoped nicht selbst → Owner-Guard im Tool. Gilt für Basis- und VK-Rezepte. force überschreibt
 * bestehende Werte.
 */
class RecipeSensorikPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_sensorik.POST';
    }

    public function getDescription(): string
    {
        return 'Bewertet die Sensorik eines team-eigenen Rezepts per KI und persistiert sie. '
            . 'force=true schreibt auch über bestehende Werte.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Rezept-Id (Basis oder VK, team-eigen).'],
                'force' => ['type' => 'boolean', 'description' => 'Bestehende Sensorik überschreiben.'],
            ],
            'required' => ['recipe_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->whereKey((int) ($arguments['recipe_id'] ?? 0))->first();
        if ($recipe === null) {
            return ToolResult::error('Rezept nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $recipe->isOwnedBy($team)) {
            return ToolResult::error('Geerbtes Rezept — Sensorik nur durchs Besitzer-Team.', 'ACCESS_DENIED');
        }

        try {
            $res = app(SensorikService::class)->bewerteRezept((int) $recipe->id, (bool) ($arguments['force'] ?? false));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['recipe_id' => (int) $recipe->id, 'sensorik' => $res]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'recipe', 'sensorik', 'ki', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'llm',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipes.GET'],
            'examples' => ['Bewerte die Sensorik von Rezept 12.'],
        ];
    }
}
