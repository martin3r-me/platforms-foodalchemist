<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistLabNote;

/** Lab-Notiz lesen: id = Einzelnotiz inkl. Body, sonst Liste (optional q auf Titel). */
class LabNotesGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.lab_notes.GET';
    }

    public function getDescription(): string
    {
        return 'Liest Lab-Notizen des aktuellen Teams. id = eine Notiz komplett (title, body, evidence_tier, source_ref, '
            . 'created_via, author_id). Ohne id = Liste (q? Titel-Filter, limit?, neueste zuerst) ohne Body.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'q' => ['type' => 'string', 'description' => 'Titel-Filter (nur Liste)'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $query = FoodAlchemistLabNote::visibleToTeam($team);
        if (! empty($arguments['id'])) {
            $note = $query->where('id', (int) $arguments['id'])->first();
            if ($note === null) {
                return ToolResult::error('Lab-Notiz nicht gefunden.', 'NOT_FOUND');
            }

            return ToolResult::success(['note' => [
                'id' => (int) $note->id, 'title' => $note->title, 'body' => $note->body,
                'evidence_tier' => $note->evidence_tier, 'source_ref' => $note->source_ref,
                'created_via' => $note->created_via, 'author_id' => $note->author_id,
                'created_at' => $note->created_at?->toIso8601String(),
                'updated_at' => $note->updated_at?->toIso8601String(),
            ]]);
        }
        $q = trim((string) ($arguments['q'] ?? ''));
        if ($q !== '') {
            $query->where('title', 'like', '%' . $q . '%');
        }
        $limit = min(200, max(1, (int) ($arguments['limit'] ?? 50)));
        $notes = $query->orderByDesc('id')->limit($limit)
            ->get(['id', 'title', 'evidence_tier', 'source_ref', 'created_at'])
            ->map(fn ($n) => [
                'id' => (int) $n->id, 'title' => $n->title, 'evidence_tier' => $n->evidence_tier,
                'source_ref' => $n->source_ref, 'created_at' => $n->created_at?->toIso8601String(),
            ])->all();

        return ToolResult::success(['notes' => $notes, 'count' => count($notes)]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'lab', 'notiz', 'r&d'],
            'read_only' => false,
            'idempotent' => true,
            'risk_level' => 'true',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.lab_notes.POST', 'foodalchemist.lab_notes.SEARCH'],
            'examples' => ['low'],
        ];
    }
}
