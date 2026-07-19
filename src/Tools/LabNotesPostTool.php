<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\LabNoteService;

/**
 * R6.11 · S3 (write): Lab-Journal-Notiz anlegen — die Senke für Hypothesen-/
 * Widerspruchs-Ergebnisse (aus knowledge.HYPOTHESIZE bzw. dem Widerspruchs-Signal).
 * Evidenz-Stufe Pflicht-Default T3 (Hypothese). Datensatz gehört dem aktuellen Team.
 */
class LabNotesPostTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.lab_notes.POST';
    }

    public function getDescription(): string
    {
        return 'Legt eine Lab-Journal-Notiz an (title Pflicht; body?, evidence_tier ∈ T0..T3 Default T3, '
            . 'source_ref? = Herkunft z. B. "hypothesis:anchor:2"). Senke für Hypothesen-/Widerspruchs-'
            . 'Ergebnisse — ehrliche Evidenz-Stufe bleibt Pflicht (T3 = Hypothese, nie Fakt). Gehört dem Team.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'string'],
                'evidence_tier' => ['type' => 'string', 'enum' => ['T0', 'T1', 'T2', 'T3'], 'default' => 'T3'],
                'source_ref' => ['type' => 'string', 'description' => 'freie Herkunft, z. B. hypothesis:anchor:2 / widerspruch:doc:5'],
            ],
            'required' => ['title'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $userId = is_object($context->user ?? null) && isset($context->user->id) ? (int) $context->user->id : null;
        try {
            $note = app(LabNoteService::class)->create($team, [
                'title' => $arguments['title'] ?? '',
                'body' => $arguments['body'] ?? null,
                'evidence_tier' => $arguments['evidence_tier'] ?? 'T3',
                'source_ref' => $arguments['source_ref'] ?? null,
                'created_via' => 'mcp',
            ], $userId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'BAD_INPUT');
        }

        return ToolResult::success([
            'id' => (int) $note->id,
            'title' => $note->title,
            'evidence_tier' => $note->evidence_tier,
            'source_ref' => $note->source_ref,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'mutation',
            'tags' => ['foodalchemist', 'lab', 'notiz', 'hypothese', 'r&d', 'forschung'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'low',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge.HYPOTHESIZE', 'foodalchemist.recipes.POST'],
            'examples' => ['Halte die Hypothese Erdbeere×Basilikum als Lab-Notiz fest'],
        ];
    }
}
