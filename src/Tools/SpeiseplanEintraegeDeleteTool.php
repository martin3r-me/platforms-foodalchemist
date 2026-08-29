<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/** MCP-Steuerbarkeit · D9: einen Speiseplan-Eintrag (Baustein) entfernen. Confirm=true Pflicht. */
class SpeiseplanEintraegeDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan_eintraege.DELETE';
    }

    public function getDescription(): string
    {
        return 'Entfernt einen einzelnen Speiseplan-Eintrag (Tag/Mahlzeit/Linie) eines team-eigenen Plans. Erfordert confirm=true.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'eintrag_id' => ['type' => 'integer', 'description' => 'Eintrag-Id.'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (destruktive Aktion).'],
            ],
            'required' => ['eintrag_id', 'confirm'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        if (($arguments['confirm'] ?? false) !== true) {
            return ToolResult::error('Löschen erfordert confirm=true (destruktive Aktion).', 'CONFIRM_REQUIRED');
        }
        $eintragId = (int) ($arguments['eintrag_id'] ?? 0);
        if (($guard = $this->guardSpeiseplanEintragOwned($team, $eintragId)) !== null) {
            return $guard;
        }

        try {
            app(SpeiseplanService::class)->removeEintrag($team, $eintragId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['eintrag_id' => $eintragId, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'eintrag', 'delete'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'destructive',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes'],
            'related_tools' => ['foodalchemist.speiseplan_eintraege.POST', 'foodalchemist.speiseplan_eintraege.PAX'],
            'examples' => ['Entferne Eintrag 88 (confirm=true).'],
        ];
    }
}
