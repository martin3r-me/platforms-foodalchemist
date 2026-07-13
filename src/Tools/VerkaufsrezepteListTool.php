<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Pagination\Paginator;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SalesRecipeService;

/**
 * #504: Vollständige, seiten-basierte Auflistung der Verkaufsrezepte (Gerichte) des
 * Teams — ergänzt verkaufsrezepte.SEARCH (Suchbegriff Pflicht, Cap 50). page/per_page-Paging
 * fürs komplette Enumerieren des Gerichte-Katalogs.
 */
class VerkaufsrezepteListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.verkaufsrezepte.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet die Verkaufsrezepte (Gerichte) des Teams vollständig und seitenweise auf (ohne '
            . 'Suchbegriff, ohne 50er-Cap). page/per_page-Paging (last_page zum Weiterblättern). Liefert je '
            . 'Gericht id, name, sales_net, ek_total_eur, speisen_klasse, presentations (Darreichungsformen). '
            . 'Gezielte Suche via foodalchemist.verkaufsrezepte.SEARCH.';
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
        $treffer = app(SalesRecipeService::class)->paginateBrowser(['search' => ''], $team, $perPage);

        return ToolResult::success([
            'total' => $treffer->total(),
            'page' => $treffer->currentPage(),
            'last_page' => $treffer->lastPage(),
            'per_page' => $treffer->perPage(),
            'verkaufsrezepte' => collect($treffer->items())->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'sales_net' => $r->sales_net,
                'ek_total_eur' => $r->ek_total_eur,
                'speisen_klasse' => $r->dishClass?->label,
                'presentations' => $this->darreichungenSummary($r),
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'verkaufsrezept', 'gericht', 'list', 'katalog', 'paging'],
            'related_tools' => ['foodalchemist.verkaufsrezepte.SEARCH'],
            'examples' => ['Liste alle Gerichte auf', 'Zeig mir den kompletten Verkaufsrezept-Katalog seitenweise'],
        ];
    }
}
