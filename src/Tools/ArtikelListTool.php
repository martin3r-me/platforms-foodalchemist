<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Pagination\Paginator;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SupplierItemService;

/**
 * #504: Vollständige, seiten-basierte Auflistung der Lieferantenartikel des Teams —
 * ergänzt artikel.SEARCH (Suchbegriff, Cap 50). page/per_page-Paging fürs komplette
 * Enumerieren des Artikel-Katalogs.
 */
class ArtikelListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.artikel.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet die Lieferantenartikel des Teams vollständig und seitenweise auf (ohne Suchbegriff, '
            . 'ohne 50er-Cap). page/per_page-Paging (last_page zum Weiterblättern). Liefert je Artikel '
            . 'id, designation, article_number, supplier. Gezielte Suche via foodalchemist.artikel.SEARCH.';
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
        $treffer = app(SupplierItemService::class)->searchGlobal($team, '', [], $perPage);

        return ToolResult::success([
            'total' => $treffer->total(),
            'page' => $treffer->currentPage(),
            'last_page' => $treffer->lastPage(),
            'per_page' => $treffer->perPage(),
            'artikel' => collect($treffer->items())->map(fn ($i) => [
                'id' => $i->id, 'designation' => $i->designation,
                'article_number' => $i->article_number,
                'supplier' => $i->supplier?->name ?? null,
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'artikel', 'lieferantenartikel', 'list', 'katalog', 'paging'],
            'related_tools' => ['foodalchemist.artikel.SEARCH'],
            'examples' => ['Liste alle Lieferantenartikel auf', 'Zeig mir den kompletten Artikel-Katalog seitenweise'],
        ];
    }
}
