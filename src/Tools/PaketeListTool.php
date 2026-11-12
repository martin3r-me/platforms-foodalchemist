<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\ConceptPaketService;
use Platform\FoodAlchemist\Services\ConceptService;

/** Concepter-Pakete seitenweise listen (wie der Concepter-Reiter Pakete). */
class PaketeListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.pakete.LIST';
    }

    public function getDescription(): string
    {
        return 'Pakete = Concepter-Pakete (concepts mit kind=paket), dieselben, die der Concepter im Reiter „Pakete“ zeigt. Listet sichtbare Pakete seitenweise (page, per_page, last_page) mit Anzahl Positionen. Filter: q (Name), class, status. Freitext auch: pakete.SEARCH.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'description' => 'Suche im Namen.'],
                'class' => ['type' => 'string', 'description' => 'Optionaler Klassen-Filter.'],
                'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'archiviert'], 'description' => 'Optionaler Status-Filter.'],
                'per_page' => ['type' => 'integer', 'description' => 'Seitengröße (Default 100).'],
                'page' => ['type' => 'integer', 'description' => 'Seite (ab 1).'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $filters = array_filter([
            'search' => trim((string) ($arguments['q'] ?? '')),
            'class' => trim((string) ($arguments['class'] ?? '')),
            'status' => trim((string) ($arguments['status'] ?? '')),
        ], fn ($v) => $v !== '');
        $seite = max(1, (int) ($arguments['page'] ?? 1));
        \Illuminate\Pagination\Paginator::currentPageResolver(fn () => $seite);
        $perPage = (int) ($arguments['per_page'] ?? 100);
        $page = app(ConceptService::class)->paginatePakete($filters, $team, $perPage > 0 ? $perPage : 100);
        $svc = app(ConceptPaketService::class);

        return $this->erfolgMitWarnungen([
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'pakete' => collect($page->items())->map(fn ($p) => $svc->payload($p) + ['positionen' => (int) $p->slots_count])->all(),
        ], $arguments);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'paket', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [],
            'related_tools' => ['foodalchemist.pakete.SEARCH', 'foodalchemist.pakete.GET'],
            'examples' => ['Liste alle Pakete im Entwurf.'],
        ];
    }
}
