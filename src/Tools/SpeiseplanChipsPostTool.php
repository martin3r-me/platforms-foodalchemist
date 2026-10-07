<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeiseplanVorgabenService;

/** Spec 59 · MCP im Lockstep: Speiseplan-Prüf-Chip anlegen (oder den Startsatz). */
class SpeiseplanChipsPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan_chips.POST';
    }

    public function getDescription(): string
    {
        return 'Legt einen team-eigenen Speiseplan-Chip an (label, kriterien = ODER-Liste aus '
            . '{art: diaet, key: vegan|vegetarisch|fleisch|fisch|schwein|rind} und {art: hauptgruppe, id}; optional '
            . 'default_min, default_max, sort_order). Mit standard=true stattdessen den Startsatz Vegan/Vegetarisch/'
            . 'Fleisch/Fisch/Schwein — nur wenn das Team noch keine eigenen Chips hat.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'label' => ['type' => 'string', 'description' => 'Anzeigename, z. B. „Suppe“.'],
                'kriterien' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'ODER-Bedingungen.'],
                'default_min' => ['type' => 'integer', 'description' => 'Vorschlag mind. je Woche.'],
                'default_max' => ['type' => 'integer', 'description' => 'Vorschlag höchstens je Woche.'],
                'sort_order' => ['type' => 'integer', 'description' => 'Sortierung.'],
                'standard' => ['type' => 'boolean', 'description' => 'true = Startsatz anlegen (übrige Felder werden ignoriert).'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(SpeiseplanVorgabenService::class);
        if ((bool) ($arguments['standard'] ?? false)) {
            $n = $svc->standardChipsAnlegen($team);

            return $n > 0
                ? ToolResult::success(['angelegt' => $n])
                : ToolResult::error('Das Team hat schon eigene Chips — Startsatz nur für ein leeres Team.', 'VALIDATION_ERROR');
        }

        try {
            $chip = $svc->chipAnlegen($team, array_intersect_key($arguments, array_flip(['label', 'kriterien', 'default_min', 'default_max', 'sort_order'])));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['id' => (int) $chip->id, 'label' => $chip->label, 'kriterien' => $chip->kriterien]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'vorgaben', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.speiseplan_chips.GET', 'foodalchemist.speiseplan_chips.PUT', 'foodalchemist.speiseplaene.PUT'],
            'examples' => ['Lege einen Speiseplan-Chip „Suppe“ für die Hauptgruppe Suppen an.'],
        ];
    }
}
