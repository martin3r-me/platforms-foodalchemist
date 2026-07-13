<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Pagination\Paginator;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GpService;

/**
 * #504: Vollständige, seiten-basierte Auflistung der Grundprodukte (GPs) des Teams —
 * ergänzt gps.SEARCH (das braucht einen Suchbegriff und cappt bei 50). Offset-freies
 * page/per_page-Paging fürs komplette Katalog-Enumerieren. Detail via gps.GET.
 */
class GpsListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gps.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet die Grundprodukte (GPs) des Teams vollständig und seitenweise auf (ohne Suchbegriff, '
            . 'ohne 50er-Cap). page/per_page-Paging (last_page zum Weiterblättern). Liefert je GP '
            . 'id, name, status, main_ingredient_slug. Details via foodalchemist.gps.GET, gezielte Suche via foodalchemist.gps.SEARCH.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
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
        $treffer = app(GpService::class)->paginate(['search' => ''], $team, $perPage);

        return ToolResult::success([
            'total' => $treffer->total(),
            'page' => $treffer->currentPage(),
            'last_page' => $treffer->lastPage(),
            'per_page' => $treffer->perPage(),
            'gps' => collect($treffer->items())->map(fn ($gp) => [
                'id' => $gp->id, 'name' => $gp->name, 'status' => $gp->status,
                'main_ingredient_slug' => $gp->main_ingredient_slug,
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'gp', 'grundprodukt', 'list', 'katalog', 'paging'],
            'related_tools' => ['foodalchemist.gps.SEARCH', 'foodalchemist.gps.GET'],
            'examples' => ['Liste alle Grundprodukte auf', 'Zeig mir den kompletten GP-Katalog seitenweise'],
        ];
    }
}
