<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanChip;
use Platform\FoodAlchemist\Services\SpeiseplanVorgabenService;

/** Spec 59 · MCP im Lockstep: Speiseplan-Prüf-Chips (Katalog für Plan-Vorgaben) lesen. */
class SpeiseplanChipsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan_chips.GET';
    }

    public function getDescription(): string
    {
        return 'Listet den Speiseplan-Chip-Katalog des Teams (inkl. geerbter aus dem Eltern-Team): id, label, '
            . 'kriterien (ODER: {art: diaet, key: vegan|vegetarisch|fleisch|fisch|schwein|rind} oder {art: hauptgruppe, id}), '
            . 'default_min/default_max, is_active, eigen. Die chip_id wird in speiseplaene.PUT felder.vorgaben verwendet.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'nur_aktive' => ['type' => 'boolean', 'description' => 'Nur aktive Chips (Standard: alle).'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $chips = app(SpeiseplanVorgabenService::class)->katalog($team, (bool) ($arguments['nur_aktive'] ?? false));

        return ToolResult::success([
            'diaeten' => array_keys(FoodAlchemistSpeiseplanChip::DIAETEN),
            'chips' => $chips->map(fn ($c) => [
                'id' => (int) $c->id, 'label' => $c->label, 'kriterien' => (array) $c->kriterien,
                'default_min' => $c->default_min, 'default_max' => $c->default_max,
                'sort_order' => (int) $c->sort_order, 'is_active' => (bool) $c->is_active,
                'eigen' => $c->isOwnedBy($team),
            ])->values()->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'speiseplan', 'vorgaben', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.speiseplan_chips.POST', 'foodalchemist.speiseplan_chips.PUT', 'foodalchemist.speiseplaene.PUT'],
            'examples' => ['Welche Speiseplan-Chips gibt es für Vorgaben?'],
        ];
    }
}
