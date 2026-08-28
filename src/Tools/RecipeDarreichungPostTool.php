<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\DarreichungService;

/**
 * MCP-Steuerbarkeit · D3: Darreichungsform (Servierform) an einem team-eigenen Gericht anlegen.
 * serving_form = Slug/Code/Label (team-scoped ∪ global aufgelöst). Erste Form wird Standard.
 */
class RecipeDarreichungPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_darreichung.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine Darreichungsform an einem team-eigenen Gericht an (serving_form = Slug/Code/Label). '
            . 'attrs optional: quantity_per_unit_g, unit_count, markup_class_id, price_mode (auto|manuell), sales_net, note.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'recipe_id' => ['type' => 'integer', 'description' => 'Gericht-Id (team-eigen).'],
                'serving_form' => ['type' => 'string', 'description' => 'Servierform (Slug/Code/Label).'],
                'attrs' => ['type' => 'object', 'description' => 'Optionale Felder der Darreichung.'],
            ],
            'required' => ['recipe_id', 'serving_form'],
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
            return ToolResult::error('Gericht nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        if (! $recipe->isOwnedBy($team)) {
            return ToolResult::error('Darreichungen nur fürs Besitzer-Team.', 'ACCESS_DENIED');
        }

        try {
            $formId = $this->resolveServierformId($team, (string) ($arguments['serving_form'] ?? ''));
            if ($formId === null) {
                return ToolResult::error('serving_form ist Pflicht.', 'VALIDATION_ERROR');
            }
            $attrs = is_array($arguments['attrs'] ?? null) ? $arguments['attrs'] : [];
            $dar = app(DarreichungService::class)->anlegen($team, (int) $recipe->id, $formId, $attrs, 'mcp');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success([
            'presentation_id' => (int) $dar->id,
            'recipe_id' => (int) $recipe->id,
            'serving_form_id' => (int) $dar->serving_form_id,
            'is_standard' => (bool) $dar->is_standard,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'darreichung', 'servierform', 'gericht', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.recipe_darreichung.PUT', 'foodalchemist.recipe_darreichung.STANDARD'],
            'examples' => ['Lege am Gericht 501 die Darreichung „Teller" an.'],
        ];
    }
}
