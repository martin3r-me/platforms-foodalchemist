<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeTemplateService;

/**
 * MCP-Steuerbarkeit · D2: Basisrezept aus einer Vorlage (Template) instanziieren.
 *
 * Kopiert die Vorlage (sichtbar, basis, is_template) und bindet die Platzhalter-Slots optional an
 * konkrete GPs oder Sub-Rezepte. Idempotent (team-eigenes Rezept gleichen Namens wird wiederverwendet).
 * Ungültige Bindungen werden vom Service still übersprungen (Ergebnis nennt die Zahl gebundener Slots).
 */
class RecipesInstantiateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.INSTANTIATE';
    }

    public function getDescription(): string
    {
        return 'Instanziiert ein Basisrezept aus einer Vorlage. bindings ordnet Platzhalter-Slots (ingredient_id) '
            . 'einem gp_id oder referenced_recipe_id zu (sichtbar). Idempotent per Name.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'template_id' => ['type' => 'integer', 'description' => 'Vorlage-Basisrezept-Id (is_template, sichtbar).'],
                'name' => ['type' => 'string', 'description' => 'Name der Instanz.'],
                'bindings' => [
                    'type' => 'array',
                    'description' => 'Slot-Bindungen.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'ingredient_id' => ['type' => 'integer', 'description' => 'Zutat-Slot-Id der Vorlage (Platzhalter).'],
                            'gp_id' => ['type' => 'integer', 'description' => 'Ziel-GP (alternativ zu referenced_recipe_id).'],
                            'referenced_recipe_id' => ['type' => 'integer', 'description' => 'Ziel-Sub-Rezept.'],
                        ],
                        'required' => ['ingredient_id'],
                    ],
                ],
            ],
            'required' => ['template_id', 'name'],
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

        $templateId = (int) ($arguments['template_id'] ?? 0);
        $exists = FoodAlchemistRecipe::visibleToTeam($team)->where('is_sales_recipe', false)
            ->where('is_template', true)->whereKey($templateId)->exists();
        if (! $exists) {
            return ToolResult::error('Vorlage (Template) nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        // MCP-API (Liste von Objekten) → Service-Format [ingredient_id => ['gp_id'|'referenced_recipe_id' => …]]
        $map = [];
        foreach ((array) ($arguments['bindings'] ?? []) as $b) {
            if (! is_array($b) || ! isset($b['ingredient_id'])) {
                continue;
            }
            $slot = (int) $b['ingredient_id'];
            if (isset($b['referenced_recipe_id'])) {
                $map[$slot] = ['referenced_recipe_id' => (int) $b['referenced_recipe_id']];
            } elseif (isset($b['gp_id'])) {
                $map[$slot] = ['gp_id' => (int) $b['gp_id']];
            }
        }

        try {
            $res = app(RecipeTemplateService::class)->instantiate($team, $templateId, $name, $map);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'id' => (int) ($res['id'] ?? 0),
            'created' => (bool) ($res['created'] ?? false),
            'gebunden' => (int) ($res['gebunden'] ?? 0),
            'slots' => (int) ($res['slots'] ?? 0),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'recipe', 'template', 'instanziieren', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.recipes.TEMPLATE_TOGGLE', 'foodalchemist.recipes.GET'],
            'examples' => ['Instanziiere Vorlage 12 als „Grundfond: Wild".'],
        ];
    }
}
