<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistLabNote;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;

/**
 * Spec 15 §5b: R&D-Lab-Notes durchsuchen („schon mal hypothetisiert?"). Hybrid:
 * lexikalisch (Titel) plus — sofern der Embedding-Provider aktiv ist — ein
 * semantischer Pass über Titel/Body (via: lexical|semantic je Treffer).
 */
class LabNotesSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.lab_notes.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Durchsucht die Lab-Notes (R&D-Hypothesen/Notizen) des aktuellen Teams. Hybrid: lexikalisch '
            . '(Titel) plus — sofern der Embedding-Provider aktiv ist — ein semantischer Pass über Titel/Body '
            . '(via: lexical|semantic je Treffer). Ohne Provider rein lexikalisch. Liefert id, title, '
            . 'evidence_tier, source_ref. Nutzen: „wurde X schon einmal hypothetisiert/getestet?".';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'description' => 'Suchbegriff (Titel/Inhalt)'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 15],
            ],
            'required' => ['q'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $q = trim((string) $arguments['q']);
        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 15)));

        $cols = ['id', 'title', 'evidence_tier', 'source_ref'];
        $notes = $q === '' ? []
            : FoodAlchemistLabNote::visibleToTeam($team)
                ->where('title', 'like', '%' . $q . '%')
                ->orderBy('title')->limit($limit)->get($cols)
                ->map(fn ($n) => [
                    'id' => $n->id, 'title' => $n->title, 'evidence_tier' => $n->evidence_tier,
                    'source_ref' => $n->source_ref, 'via' => 'lexical',
                ])->all();

        // Spec 15 §5b: semantische Ergänzung — nur was die Lexik NICHT schon fand.
        $semScores = $this->semanticPoolIds($team, $q, PoolEmbeddingService::ENTITY_TYPE_LAB_NOTE, array_column($notes, 'id'), $limit);
        if ($semScores !== []) {
            $rows = FoodAlchemistLabNote::visibleToTeam($team)->whereIn('id', array_keys($semScores))->get($cols)->keyBy('id');
            arsort($semScores);
            foreach ($semScores as $id => $score) {
                $n = $rows->get($id);
                if ($n === null || count($notes) >= $limit) {
                    continue;
                }
                $notes[] = [
                    'id' => $n->id, 'title' => $n->title, 'evidence_tier' => $n->evidence_tier,
                    'source_ref' => $n->source_ref,
                    'via' => 'semantic', 'semantic_score' => round($score, 3),
                ];
            }
        }

        return ToolResult::success(['total' => count($notes), 'lab_notes' => $notes]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'lab-note', 'rnd', 'search'],
            'examples' => ['Wurde Miso-Karamell schon getestet?', 'Suche Lab-Notes zu Fermentation'],
        ];
    }
}
