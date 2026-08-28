<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\DarreichungService;

/**
 * MCP-Steuerbarkeit · D3: Mengen-Delta einer Zutat je Darreichungsform setzen/entfernen
 * (form-spezifische Abweichung vom Kerngericht: Override-Menge oder weggelassen).
 */
class RecipeDarreichungDeltaPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipe_darreichung_delta.PUT';
    }

    public function getDescription(): string
    {
        return 'Setzt/entfernt das Mengen-Delta einer Zutat für eine Darreichungsform. action=set mit '
            . 'quantity_override_g (oder omitted=true zum Weglassen); action=remove entfernt das Delta.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'presentation_id' => ['type' => 'integer', 'description' => 'Darreichungs-Id.'],
                'recipe_ingredient_id' => ['type' => 'integer', 'description' => 'Zutat-Zeilen-Id des Gerichts.'],
                'quantity_override_g' => ['type' => 'number', 'description' => 'Abweichende Menge in g (bei action=set).'],
                'omitted' => ['type' => 'boolean', 'description' => 'Zutat in dieser Form weglassen (bei action=set).'],
                'action' => ['type' => 'string', 'enum' => ['set', 'remove'], 'description' => 'Setzen oder entfernen.'],
            ],
            'required' => ['presentation_id', 'recipe_ingredient_id', 'action'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $action = (string) ($arguments['action'] ?? '');
        if (! in_array($action, ['set', 'remove'], true)) {
            return ToolResult::error('action muss set|remove sein.', 'VALIDATION_ERROR');
        }

        $id = (int) ($arguments['presentation_id'] ?? 0);
        if (($guard = $this->guardDarreichungOwned($team, $id)) !== null) {
            return $guard;
        }
        $zutatId = (int) ($arguments['recipe_ingredient_id'] ?? 0);

        try {
            if ($action === 'set') {
                $menge = isset($arguments['quantity_override_g']) ? (float) $arguments['quantity_override_g'] : null;
                app(DarreichungService::class)->setzeDelta($team, $id, $zutatId, $menge, (bool) ($arguments['omitted'] ?? false));
            } else {
                app(DarreichungService::class)->entferneDelta($team, $id, $zutatId);
            }
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['presentation_id' => $id, 'recipe_ingredient_id' => $zutatId, 'action' => $action]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'darreichung', 'delta', 'menge', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.recipe_darreichung.PUT'],
            'examples' => ['Setze bei Darreichung 88 für Zutat 12 die Menge auf 30 g.'],
        ];
    }
}
