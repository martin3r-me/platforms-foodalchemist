<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;

/**
 * Ebene 2: Betrieb/Standort (Outlet) im eigenen Team anlegen. Kalkulations-Overrides
 * setzt danach outlet_settings.PUT; die Präsentations-Vorlage/Optik pflegt die UI (Slice D/F).
 */
class OutletPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.outlets.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen Betrieb/Standort (Outlet) im eigenen Team an (Name eindeutig je Team). '
            . 'Danach Overrides via outlet_settings.PUT; auflisten via outlets.GET.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Name des Betriebs (eindeutig im Team).'],
            ],
            'required' => ['name'],
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
            return ToolResult::error('name darf nicht leer sein.', 'VALIDATION_ERROR');
        }
        if (FoodAlchemistOutlet::where('team_id', $team->id)->where('name', $name)->exists()) {
            return ToolResult::error('Ein Betrieb mit diesem Namen existiert bereits.', 'VALIDATION_ERROR');
        }

        $outlet = FoodAlchemistOutlet::create(['team_id' => $team->id, 'name' => $name]);

        return ToolResult::success(['id' => (int) $outlet->id, 'name' => $outlet->name]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'betrieb', 'outlet', 'standort', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.outlets.GET', 'foodalchemist.outlet_settings.PUT'],
            'examples' => ['Lege den Betrieb „Kantine Süd" an.'],
        ];
    }
}
