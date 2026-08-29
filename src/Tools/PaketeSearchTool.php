<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\PaketService;

/** MCP-Steuerbarkeit · D5d: Pakete per Freitext suchen. Read-only. */
class PaketeSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.pakete.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Sucht sichtbare Pakete per Freitext (Name), optional mit role-Filter.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Suchbegriff (Paketname).'],
                'role' => ['type' => 'string', 'description' => 'Optionaler Rollen-Filter.'],
                'per_page' => ['type' => 'integer', 'description' => 'Seitengröße (Default 100).'],
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
        $q = trim((string) ($arguments['query'] ?? ''));
        $filters = ['search' => $q];
        if (($role = trim((string) ($arguments['role'] ?? ''))) !== '') {
            $filters['role'] = $role;
        }
        $perPage = (int) ($arguments['per_page'] ?? 100);
        $limit = $perPage > 0 ? $perPage : 100;
        $page = app(PaketService::class)->paginateBrowser($filters, $team, $limit);
        $out = collect($page->items())->map(fn ($p) => $this->paketPayload($p) + ['via' => 'lexical'])->all();

        // Hybrid (Ausbau b): semantischer Pass über den Paket-Pool — ergänzt nur NEUES.
        $sem = $this->semanticPoolIds($team, $q, PoolEmbeddingService::ENTITY_TYPE_PAKET, array_column($out, 'id'), $limit);
        if ($sem !== []) {
            arsort($sem);
            $rows = FoodAlchemistPaket::visibleToTeam($team)->whereIn('id', array_keys($sem))->get()->keyBy('id');
            foreach ($sem as $id => $score) {
                $p = $rows->get($id);
                if ($p === null || count($out) >= $limit) {
                    continue;
                }
                $out[] = $this->paketPayload($p) + ['via' => 'semantic', 'semantic_score' => round($score, 3)];
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
