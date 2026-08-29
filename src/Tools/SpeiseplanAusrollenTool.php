<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/**
 * MCP-Steuerbarkeit · D9: den Zyklus eines Speiseplans bis zu einem Datum ausrollen (Massen-Insert
 * von Einträgen). Destruktiv-umfangreich → confirm=true Pflicht.
 */
class SpeiseplanAusrollenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan.AUSROLLEN';
    }

    public function getDescription(): string
    {
        return 'Rollt den Speiseplan-Zyklus bis zu einem Enddatum aus (erzeugt die Einträge der Folgewochen). '
            . 'Umfangreicher Massen-Insert — erfordert confirm=true. bis_datum als YYYY-MM-DD.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan_id' => ['type' => 'integer', 'description' => 'Speiseplan-Id.'],
                'bis_datum' => ['type' => 'string', 'description' => 'Enddatum YYYY-MM-DD.'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (Massen-Insert).'],
            ],
            'required' => ['plan_id', 'bis_datum', 'confirm'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Ausrollen erfordert confirm=true (Massen-Insert).', 'CONFIRM_REQUIRED');
        }
        $bisDatum = trim((string) ($arguments['bis_datum'] ?? ''));
        if ($bisDatum === '') {
            return ToolResult::error('bis_datum ist Pflicht (YYYY-MM-DD).', 'VALIDATION_ERROR');
        }
        $planId = (int) ($arguments['plan_id'] ?? 0);
        if (($guard = $this->guardOwned($team, FoodAlchemistSpeiseplan::class, $planId, 'Speiseplan')) !== null) {
            return $guard;
        }

        try {
            $count = app(SpeiseplanService::class)->vorlageAusrollen($team, $planId, $bisDatum);
        } catch (\RuntimeException | \Carbon\Exceptions\InvalidFormatException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['plan_id' => $planId, 'bis_datum' => $bisDatum, 'created_entries' => (int) $count]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'ausrollen', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'destructive',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.speiseplan_eintraege.POST'],
            'examples' => ['Rolle Speiseplan 3 bis 2027-12-31 aus (confirm=true).'],
        ];
    }
}
