<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SpeiseplanService;

/**
 * MCP-Steuerbarkeit · D9: Ausgabe-Linie (Baustein) entfernen. Einträge der Linie werden entkoppelt
 * (nicht gelöscht). Confirm=true Pflicht.
 */
class SpeiseplanLinienDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.speiseplan_linien.DELETE';
    }

    public function getDescription(): string
    {
        return 'Entfernt eine Ausgabe-Linie eines team-eigenen Speiseplans (ihre Einträge werden entkoppelt, nicht gelöscht). Erfordert confirm=true.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'linie_id' => ['type' => 'integer', 'description' => 'Linien-Id.'],
                'confirm' => ['type' => 'boolean', 'description' => 'Muss true sein (destruktive Aktion).'],
            ],
            'required' => ['linie_id', 'confirm'],
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
        $linieId = (int) ($arguments['linie_id'] ?? 0);
        if (($guard = $this->guardSpeiseplanLinieOwned($team, $linieId)) !== null) {
            return $guard;
        }

        try {
            app(SpeiseplanService::class)->removeLinie($team, $linieId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['linie_id' => $linieId, 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'speiseplan', 'linie', 'delete'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'destructive',
            'confirmation_required' => true,
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['deletes', 'updates'],
            'related_tools' => ['foodalchemist.speiseplan_linien.POST'],
            'examples' => ['Entferne Linie 5 (confirm=true).'],
        ];
    }
}
