<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistLabNote;

/** Lab-Notiz löschen (Soft-Delete; nur Notizen des eigenen Teams). */
class LabNotesDeleteTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.lab_notes.DELETE';
    }

    public function getDescription(): string
    {
        return 'Löscht eine Lab-Notiz des eigenen Teams (id Pflicht; Soft-Delete, wiederherstellbar über die Datenbank).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $note = FoodAlchemistLabNote::where('team_id', $team->id)->where('id', (int) ($arguments['id'] ?? 0))->first();
        if ($note === null) {
            return ToolResult::error('Lab-Notiz nicht gefunden (oder gehört einem anderen Team).', 'NOT_FOUND');
        }
        $note->delete();

        return ToolResult::success(['id' => (int) $arguments['id'], 'deleted' => true]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'mutation',
            'tags' => ['foodalchemist', 'lab', 'notiz', 'r&d'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'medium',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.lab_notes.POST', 'foodalchemist.lab_notes.SEARCH'],
            'examples' => ['Lösche die doppelte Lab-Notiz 1'],
        ];
    }
}
