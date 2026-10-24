<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistLabNote;

/** Lab-Notiz bearbeiten (nur Notizen des eigenen Teams; nur übergebene Felder werden geändert). */
class LabNotesPutTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    private const TIERS = ['T0', 'T1', 'T2', 'T3'];

    public function getName(): string
    {
        return 'foodalchemist.lab_notes.PUT';
    }

    public function getDescription(): string
    {
        return 'Ändert eine Lab-Notiz des eigenen Teams (id Pflicht; title?, body?, evidence_tier? ∈ T0..T3, source_ref?). '
            . 'Nur übergebene Felder werden geändert; leerer String bei body/source_ref leert das Feld.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'body' => ['type' => 'string'],
                'evidence_tier' => ['type' => 'string', 'enum' => self::TIERS],
                'source_ref' => ['type' => 'string'],
            ],
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
        if (array_key_exists('title', $arguments)) {
            $title = trim((string) $arguments['title']);
            if ($title === '') {
                return ToolResult::error('Lab-Notiz braucht einen Titel.', 'BAD_INPUT');
            }
            $note->title = $title;
        }
        if (array_key_exists('body', $arguments)) {
            $note->body = ((string) $arguments['body']) ?: null;
        }
        if (array_key_exists('source_ref', $arguments)) {
            $note->source_ref = ((string) $arguments['source_ref']) ?: null;
        }
        if (array_key_exists('evidence_tier', $arguments)) {
            if (! in_array($arguments['evidence_tier'], self::TIERS, true)) {
                return ToolResult::error('evidence_tier muss T0..T3 sein.', 'BAD_INPUT');
            }
            $note->evidence_tier = $arguments['evidence_tier'];
        }
        $note->save();

        return ToolResult::success([
            'id' => (int) $note->id, 'title' => $note->title,
            'evidence_tier' => $note->evidence_tier, 'source_ref' => $note->source_ref,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'mutation',
            'tags' => ['foodalchemist', 'lab', 'notiz', 'r&d'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'low',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.lab_notes.POST', 'foodalchemist.lab_notes.SEARCH'],
            'examples' => ['Ändere den Titel der Lab-Notiz 1'],
        ];
    }
}
