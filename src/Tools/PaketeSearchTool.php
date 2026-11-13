<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\ConceptPaketService;
use Platform\FoodAlchemist\Services\ConceptService;

/** Concepter-Pakete per Freitext suchen. */
class PaketeSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.pakete.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Pakete = Concepter-Pakete (concepts mit kind=paket), dieselben, die der Concepter im Reiter „Pakete“ zeigt. Sucht sichtbare Pakete per Freitext (Name), optional class/status. Hybrid: lexikalisch, ergänzt um semantische Treffer aus dem Concept-Pool (via: lexical|semantic).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Suchbegriff (Paketname).'],
                'class' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'archiviert']],
                'per_page' => ['type' => 'integer', 'description' => 'Max. Treffer (Default 100).'],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $filters = array_filter([
            'search' => trim((string) ($arguments['query'] ?? '')),
            'class' => trim((string) ($arguments['class'] ?? '')),
            'status' => trim((string) ($arguments['status'] ?? '')),
        ], fn ($v) => $v !== '');
        $limit = max(1, (int) ($arguments['per_page'] ?? 100));
        $page = app(ConceptService::class)->paginatePakete($filters, $team, $limit);
        $svc = app(ConceptPaketService::class);
        $out = collect($page->items())->map(fn ($p) => $svc->payload($p) + ['via' => 'lexical'])->all();

        // Semantische Ergänzung (Concept-Pool, wie concepts.SEARCH kind=paket) — nur was die Lexik nicht schon fand.
        $sem = $this->semanticPoolIds($team, $filters['search'] ?? '', PoolEmbeddingService::ENTITY_TYPE_CONCEPT, array_column($out, 'id'), $limit - count($out));
        if ($sem !== []) {
            $rows = FoodAlchemistConcept::visibleToTeam($team)->pakete()->echte()->whereIn('id', array_keys($sem))
                ->when(isset($filters['class']), fn ($w) => $w->where('class', $filters['class']))
                ->when(isset($filters['status']), fn ($w) => $w->where('status', $filters['status']))
                ->get()->keyBy('id');
            arsort($sem);
            foreach ($sem as $id => $score) {
                if (($p = $rows->get($id)) !== null && count($out) < $limit) {
                    $out[] = $svc->payload($p) + ['via' => 'semantic', 'semantic_score' => round($score, 3)];
                }
            }
        }

        return ToolResult::success(['total' => count($out), 'pakete' => $out]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'paket', 'search', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.pakete.LIST', 'foodalchemist.pakete.GET'],
            'examples' => ['Suche Pakete mit „Grill".'],
        ];
    }
}
