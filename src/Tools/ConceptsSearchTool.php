<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\ConceptService;

/** Phase C: Gerichte-Concepter durchsuchen (Katalog, ohne angebots-lokale Entwürfe). */
class ConceptsSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Durchsucht die Gerichte-Konzepte (Concepter-Katalog) nach Name/Anlass. Hybrid: lexikalisch '
            . 'plus — sofern der Embedding-Provider aktiv ist — ein semantischer Pass über Titel/Facetten/'
            . 'Beschreibung (via: lexical|semantic je Treffer). Liefert id, name, status, class, occasion, '
            . 'slot-Anzahl — Details (Slots, Gerichte, Preise) via foodalchemist.concepts.GET. '
            . 'Konzepte sind die Bausteine für Foodbook-Blöcke (concept_ref) und Speiseplan-Einträge.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'description' => 'Suchbegriff (Name/Anlass), leer = alle'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'archiviert']],
                'vorlagen' => ['type' => 'boolean', 'default' => false, 'description' => 'true = nur Vorlagen'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 15],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $q = (string) ($arguments['q'] ?? '');
        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 15)));
        $treffer = app(ConceptService::class)->paginateBrowser([
            'search' => $q,
            'status' => (string) ($arguments['status'] ?? ''),
            'vorlagen' => (bool) ($arguments['vorlagen'] ?? false),
        ], $team, $limit);

        $concepts = collect($treffer->items())->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->name, 'status' => $c->status, 'class' => $c->class,
            'occasion' => $c->occasion, 'level' => $c->level, 'slots' => $c->slots_count, 'via' => 'lexical',
        ])->all();

        // Spec 15 §5a: semantische Ergänzung — nur was die Lexik NICHT schon fand.
        $semScores = $this->semanticPoolIds($team, $q, PoolEmbeddingService::ENTITY_TYPE_CONCEPT, array_column($concepts, 'id'), $limit);
        if ($semScores !== []) {
            $rows = FoodAlchemistConcept::visibleToTeam($team)->whereIn('id', array_keys($semScores))
                ->get(['id', 'name', 'status', 'class', 'occasion', 'level'])->keyBy('id');
            arsort($semScores);
            foreach ($semScores as $id => $score) {
                $c = $rows->get($id);
                if ($c === null || count($concepts) >= $limit) {
                    continue;
                }
                $concepts[] = [
                    'id' => $c->id, 'name' => $c->name, 'status' => $c->status, 'class' => $c->class,
                    'occasion' => $c->occasion, 'level' => $c->level, 'slots' => null,
                    'via' => 'semantic', 'semantic_score' => round($score, 3),
                ];
            }
        }

        return ToolResult::success(['total' => count($concepts), 'concepts' => $concepts]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'concept', 'concepter', 'konzept', 'paket', 'search'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.concepts.GET', 'foodalchemist.foodbook_blocks.POST'],
            'examples' => ['Suche Konzepte für Flying Buffet', 'Welche aktiven Konzepte gibt es?'],
        ];
    }
}
