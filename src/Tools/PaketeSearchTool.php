<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
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
        return 'Pakete = Concepter-Pakete (concepts mit kind=paket), dieselben, die der Concepter im Reiter „Pakete“ zeigt. Sucht sichtbare Pakete per Freitext (Name), optional class/status.';
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
