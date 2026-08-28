<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SalesRecipeService;

/**
 * MCP-Steuerbarkeit · D3: Regenerations-Programm (Aufbereitung je Komponente) eines Gerichts upsert.
 * Ohne id wird angelegt, mit id aktualisiert. Nur team-eigene Gerichte.
 */
class RecipeRegenerationPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_regeneration.PUT';
    }

    public function getDescription(): string
    {
        return 'Legt ein Regenerations-Programm an oder aktualisiert es (id). felder: component_label (Pflicht), '
            . 'device_vocab_id, temp_c, duration_min, core_temp_c, note.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Gericht-Id (team-eigen).'],
                'id' => ['type' => 'integer', 'description' => 'Regenerations-Id (leer = neu).'],
                'felder' => ['type' => 'object', 'description' => 'component_label, device_vocab_id, temp_c, duration_min, core_temp_c, note.'],
            ],
            'required' => ['recipe_id', 'felder'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $felder = $arguments['felder'] ?? null;
        if (! is_array($felder) || $felder === []) {
            return ToolResult::error('felder muss ein nicht-leeres Objekt sein.', 'VALIDATION_ERROR');
        }

        $recipeId = (int) ($arguments['recipe_id'] ?? 0);
        if (($guard = $this->guardVkRecipe($team, $recipeId)) !== null) {
            return $guard;
        }

        try {
            app(SalesRecipeService::class)->upsertRegeneration($team, $recipeId, $felder, isset($arguments['id']) ? (int) $arguments['id'] : null);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['recipe_id' => $recipeId, 'upserted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'gericht', 'regeneration', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipe_regeneration.DELETE', 'foodalchemist.recipe_regeneration.REORDER'],
            'examples' => ['Füge dem Gericht 501 ein Regenerations-Programm „Kombidämpfer 140°C" hinzu.'],
        ];
    }
}
