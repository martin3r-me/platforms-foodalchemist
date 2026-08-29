<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\FoodbookService;

/** MCP-Steuerbarkeit · D7: Foodbook-Stammdaten bearbeiten (Label, Gültigkeit, Tonalität, Defaults). */
class FoodbooksPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const FELDER = [
        'label', 'jahr', 'gueltig_von', 'gueltig_bis', 'outlet_id', 'personen', 'description', 'note',
        'writing_style_id', 'kundentyp', 'default_niveau', 'default_convenience', 'default_event_type_id',
        'default_serving_form_id', 'target_food_cost_pct', 'food_cost_tolerance_pp', 'creative_mode_default',
    ];

    public function getName(): string
    {
        return 'foodalchemist.foodbooks.PUT';
    }

    public function getDescription(): string
    {
        return 'Bearbeitet die Stammdaten eines team-eigenen Foodbooks (felder: label, jahr, gueltig_von/bis, '
            . 'writing_style_id, kundentyp, default_niveau/convenience, target_food_cost_pct, …). Status via foodbooks.STATUS.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Foodbook-Id.'],
                'felder' => ['type' => 'object', 'description' => 'Zu ändernde Felder (Allow-List).'],
            ],
            'required' => ['id', 'felder'],
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
        $in = array_intersect_key($felder, array_flip(self::FELDER));
        if ($in === []) {
            return ToolResult::error('Keine bekannten Felder in felder (Status via foodbooks.STATUS).', 'VALIDATION_ERROR');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistFoodbook::class, $id, 'Foodbook')) !== null) {
            return $guard;
        }

        try {
            app(FoodbookService::class)->update($team, $id, $in);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => $id, 'updated' => array_keys($in)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'foodbook', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.foodbooks.STATUS', 'foodalchemist.foodbooks.BRANDING'],
            'examples' => ['Setze bei Foodbook 5 den Schreibstil und default_niveau.'],
        ];
    }
}
