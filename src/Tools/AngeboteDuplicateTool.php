<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Services\AngebotKopieService;

/** Angebot kopieren (Aufbau, Branding, Bilder, lokale Concepts) — wie „Angebot kopieren“ im Editor. */
class AngeboteDuplicateTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebote.DUPLICATE';
    }

    public function getDescription(): string
    {
        return 'Kopiert ein Angebot als neues, unabhängiges Angebot: Stammdaten, Kapitelbaum, Blöcke, Branding (Logo/Cover/Farben), '
            . 'Kapitelbilder und angebots-lokale Concept-Entwürfe (als eigene Kopien). Die Kopie hat Status „anfrage“ und ist nicht veröffentlicht. '
            . 'Optional name (Default „<Name> (Kopie)“).';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer', 'description' => 'Angebots-Id (Quelle).'],
            'name' => ['type' => 'string', 'description' => 'Name der Kopie (optional).'],
        ], 'required' => ['id']];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $id = (int) ($arguments['id'] ?? 0);
        if (! FoodAlchemistAngebot::visibleToTeam($team)->whereKey($id)->exists()) {
            return ToolResult::error('Angebot nicht sichtbar/vorhanden.', 'NOT_FOUND');
        }
        try {
            $kopie = app(AngebotKopieService::class)->kopieren($team, $id, isset($arguments['name']) ? (string) $arguments['name'] : null);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        return ToolResult::success(['angebot' => ['id' => (int) $kopie->id, 'name' => $kopie->name, 'status' => $kopie->status], 'source_id' => $id]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'angebot', 'kopieren', 'duplicate', 'write'],
            'read_only' => false, 'idempotent' => false, 'risk_level' => 'write',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => ['creates'],
            'related_tools' => ['foodalchemist.angebote.GET', 'foodalchemist.angebote.PUT'],
            'examples' => ['Kopiere Angebot 2 als „Brand Y“.'],
        ];
    }
}
