<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\OutletSettingsService;

/**
 * Ebene 2: Betriebe/Standorte (Outlets) des Teams + ihre Kalkulations-Overrides listen.
 * NULL-Felder = „erbt vom Team". Read-only.
 */
class OutletsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.outlets.GET';
    }

    public function getDescription(): string
    {
        return 'Listet die Betriebe/Standorte (Outlets) des Teams inkl. ihrer Kalkulations-Overrides '
            . '(Marge/Ziel-Wareneinsatz/Stundensatz/Zuschläge — fehlend = erbt vom Team). Read-only. '
            . 'Overrides setzt outlet_settings.PUT; anlegen outlets.POST; VK je Betrieb via kalkulation.GET(outlet_id).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include_inactive' => ['type' => 'boolean', 'description' => 'Auch stillgelegte Betriebe listen (Default false).'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $query = FoodAlchemistOutlet::where('team_id', $team->id);
        if (! ($arguments['include_inactive'] ?? false)) {
            $query->where('is_inactive', false);
        }
        $svc = app(OutletSettingsService::class);

        $betriebe = $query->orderBy('name')->get()->map(function ($o) use ($svc) {
            $s = $svc->for($o);

            return [
                'id' => (int) $o->id,
                'name' => $o->name,
                'is_inactive' => (bool) $o->is_inactive,
                'overrides' => array_filter([
                    'margin_pct' => $s->margin_pct,
                    'target_food_cost_pct' => $s->target_food_cost_pct,
                    'stundensatz_eur' => $s->stundensatz_eur,
                    'hk2_surcharge_pct' => $s->hk2_surcharge_pct,
                    'labor_overhead_pct' => $s->labor_overhead_pct,
                ], fn ($v) => $v !== null),
            ];
        })->all();

        // Ebene 2: welche Brille gerade aktiv ist (durabel je User+Team; via outlets.SET_ACTIVE gesetzt).
        $aktiv = app(\Platform\FoodAlchemist\Services\ActiveOutletContext::class)
            ->current($team, $context->user?->id !== null ? (int) $context->user->id : null);

        return ToolResult::success([
            'team_id' => (int) $team->id,
            'active_outlet_id' => $aktiv?->id,
            'active_outlet_name' => $aktiv?->name,
            'betriebe' => $betriebe,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'betrieb', 'outlet', 'standort', 'settings', 'kalkulation'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.outlets.POST', 'foodalchemist.outlet_settings.PUT', 'foodalchemist.kalkulation.GET'],
            'examples' => ['Liste die Betriebe dieses Teams mit ihren Kalkulations-Overrides.'],
        ];
    }
}
