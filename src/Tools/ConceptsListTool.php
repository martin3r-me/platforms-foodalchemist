<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Pagination\Paginator;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\ConceptService;

/**
 * #504: Vollständige, seiten-basierte Auflistung der Gerichte-Konzepte (Concepter-Katalog)
 * des Teams — ergänzt concepts.SEARCH (Cap 50). page/per_page-Paging fürs komplette
 * Enumerieren. Detail via concepts.GET.
 */
class ConceptsListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.concepts.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet die Gerichte-Konzepte (Concepter-Katalog) des Teams vollständig und seitenweise auf '
            . '(ohne Suchbegriff, ohne 50er-Cap). Optional status-gefiltert bzw. vorlagen=true (Template-Konzepte); '
            . 'page/per_page-Paging (last_page zum Weiterblättern). Liefert je Konzept id, name, status, class, '
            . 'occasion, level, slots. Detail via foodalchemist.concepts.GET, gezielte Suche via foodalchemist.concepts.SEARCH.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'description' => 'Optionaler Status-Filter (draft/active/archiviert). Leer = alle.'],
                'vorlagen' => ['type' => 'boolean', 'default' => false, 'description' => 'true = nur Template-Konzepte.'],
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1, 'description' => 'Seitennummer (last_page aus der Vorantwort).'],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100, 'description' => 'Seitengröße (max. 200).'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $page = max(1, (int) ($arguments['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($arguments['per_page'] ?? 100)));
        Paginator::currentPageResolver(fn () => $page);
        $treffer = app(ConceptService::class)->paginateBrowser([
            'search' => '',
            'status' => (string) ($arguments['status'] ?? ''),
            'vorlagen' => (bool) ($arguments['vorlagen'] ?? false),
        ], $team, $perPage);

        return ToolResult::success([
            'total' => $treffer->total(),
            'page' => $treffer->currentPage(),
            'last_page' => $treffer->lastPage(),
            'per_page' => $treffer->perPage(),
            'concepts' => collect($treffer->items())->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'status' => $c->status, 'class' => $c->class,
                'occasion' => $c->occasion, 'level' => $c->level, 'slots' => $c->slots_count,
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'concept', 'konzept', 'list', 'katalog', 'paging'],
            'related_tools' => ['foodalchemist.concepts.SEARCH', 'foodalchemist.concepts.GET'],
            'examples' => ['Liste alle Konzepte auf', 'Zeig mir alle Template-Konzepte seitenweise'],
        ];
    }
}
