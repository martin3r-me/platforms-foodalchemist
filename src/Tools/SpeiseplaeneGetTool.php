<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/** MCP-Steuerbarkeit · D9: Speiseplan-Detail (Kopf + Linien + CRM-Kunde). Read-only (Read-Lücke geschlossen). */
class SpeiseplaeneGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplaene.GET';
    }

    public function getDescription(): string
    {
        return 'Liefert einen Speiseplan im Detail: Kopf (Status, Zyklus, Default-Pax, Budget), die Ausgabe-Linien '
            . 'und den CRM-Kunden. Wochen-/Monats-Raster werden über die Blade/Report-Fläche gerechnet.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['id' => ['type' => 'integer', 'description' => 'Speiseplan-Id.']], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $plan = app(SpeiseplanService::class)->detail($team, (int) ($arguments['id'] ?? 0));
        if ($plan === null) {
            return ToolResult::error('Speiseplan nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }

        return ToolResult::success([
            'speiseplan' => [
                'id' => (int) $plan->id, 'name' => $plan->name,
                'status' => $plan->status instanceof \BackedEnum ? $plan->status->value : $plan->status,
                'start_date' => $plan->start_date instanceof \DateTimeInterface ? $plan->start_date->format('Y-m-d') : $plan->start_date,
                'cycle_weeks' => $plan->cycle_weeks,
                'opening_days' => $plan->oeffnungstage(),
                // Spec 57 · Paket 7
                'is_template' => (bool) $plan->is_template,
                'source_plan_id' => $plan->source_plan_id !== null ? (int) $plan->source_plan_id : null,
                // Spec 59: Wochen-Vorgaben (Chip-Katalog via speiseplan_chips.GET)
                'vorgaben' => array_values((array) ($plan->vorgaben ?? [])),
                'default_pax' => $plan->default_pax,
                'budget_wareneinsatz' => $plan->budget_wareneinsatz !== null ? (float) $plan->budget_wareneinsatz : null,
                'outlet_id' => $plan->outlet_id !== null ? (int) $plan->outlet_id : null,
                'crm_company_id' => $plan->crm_company_id !== null ? (int) $plan->crm_company_id : null,
                'crm_contact_id' => $plan->crm_contact_id !== null ? (int) $plan->crm_contact_id : null,
                'linien' => $plan->lines->sortBy('sort_order')->values()->map(fn ($l) => [
                    'id' => (int) $l->id, 'name' => $l->name, 'color' => $l->color,
                    'is_vegetarian' => (bool) $l->is_vegetarian, 'sort_order' => (int) $l->sort_order,
                    // Spec 57 · Paket 2
                    'role' => $l->role, 'meal' => $l->meal, 'plu' => $l->plu,
                    'price_mode' => $l->price_mode ?: 'auto', 'price_value' => $l->price_value,
                    'target_wes_min_pct' => $l->target_wes_min_pct, 'target_wes_max_pct' => $l->target_wes_max_pct,
                    'default_pax' => $l->default_pax, 'is_standing' => (bool) $l->is_standing,
                ])->all(),
            ],
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'speiseplan', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.speiseplaene.LIST', 'foodalchemist.speiseplaene.PUT'],
            'examples' => ['Zeig mir Speiseplan 3 mit seinen Linien.'],
        ];
    }
}
