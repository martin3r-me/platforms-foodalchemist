<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Pagination\Paginator;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * #504: Vollständige, seiten-basierte Auflistung der Basisrezepte des Teams —
 * ergänzt recipes.SEARCH (Suchbegriff Pflicht, Cap 50). page/per_page-Paging fürs
 * komplette Enumerieren. Detail via recipes.GET.
 */
class RecipesListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.recipes.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet die Basisrezepte des Teams vollständig und seitenweise auf (ohne Suchbegriff, '
            . 'ohne 50er-Cap). Optional status-gefiltert; page/per_page-Paging (last_page zum Weiterblättern). '
            . 'Liefert je Rezept id, name, status, yield_kg, ek_total_eur. Detail via foodalchemist.recipes.GET, gezielte Suche via foodalchemist.recipes.SEARCH.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'description' => 'Optionaler Status-Filter (z. B. draft, review, approved). Leer = alle.'],
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
        $treffer = app(RecipeService::class)->paginateBrowser(
            ['search' => '', 'status' => (string) ($arguments['status'] ?? '')], $team, $perPage,
        );

        return ToolResult::success([
            'total' => $treffer->total(),
            'page' => $treffer->currentPage(),
            'last_page' => $treffer->lastPage(),
            'per_page' => $treffer->perPage(),
            'recipes' => collect($treffer->items())->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'status' => $r->status->value,
                'yield_kg' => $r->yield_kg, 'ek_total_eur' => $r->ek_total_eur,
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'rezept', 'basisrezept', 'list', 'katalog', 'paging'],
            'related_tools' => ['foodalchemist.recipes.SEARCH', 'foodalchemist.recipes.GET'],
            'examples' => ['Liste alle Basisrezepte auf', 'Zeig mir alle draft-Rezepte seitenweise'],
        ];
    }
}
