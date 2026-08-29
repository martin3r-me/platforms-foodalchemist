<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/** MCP-Steuerbarkeit · D9: Pax-Override eines Speiseplan-Eintrags setzen (0/leer = Plan-Default gilt). */
class SpeiseplanEintraegePaxTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan_eintraege.PAX';
    }

    public function getDescription(): string
    {
        return 'Setzt den Pax-Override eines Speiseplan-Eintrags (pax=0/weglassen → NULL = Plan-Default gilt).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'eintrag_id' => ['type' => 'integer', 'description' => 'Eintrag-Id.'],
                'pax' => ['type' => 'integer', 'description' => 'Personen (0 = Default).'],
            ],
            'required' => ['eintrag_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $eintragId = (int) ($arguments['eintrag_id'] ?? 0);
        if (($guard = $this->guardSpeiseplanEintragOwned($team, $eintragId)) !== null) {
            return $guard;
        }

        try {
            app(SpeiseplanService::class)->setEintragPax($team, $eintragId, $arguments['pax'] ?? 0);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['eintrag_id' => $eintragId, 'pax' => (int) ($arguments['pax'] ?? 0)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'eintrag', 'pax', 'write'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['updates'],
            'related_tools' => ['foodalchemist.speiseplan_eintraege.POST'],
            'examples' => ['Setze bei Eintrag 88 die Pax auf 120.'],
        ];
    }
}
