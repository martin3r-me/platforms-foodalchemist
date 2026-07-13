<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Pagination\Paginator;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\AngebotService;

/**
 * #504: Vollständige, seiten-basierte Auflistung der Angebote des Teams — ergänzt
 * angebote.SEARCH (Cap 50). page/per_page-Paging fürs komplette Enumerieren. Detail +
 * Kalkulation via angebote.GET.
 */
class AngeboteListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebote.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet die Angebote des Teams vollständig und seitenweise auf (ohne Suchbegriff, ohne 50er-Cap). '
            . 'Optional status-gefiltert; page/per_page-Paging (last_page zum Weiterblättern). Liefert je Angebot '
            . 'id, name, status, occasion, personen. Detail + Kalkulation via foodalchemist.angebote.GET, gezielte Suche via foodalchemist.angebote.SEARCH.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'description' => 'Optionaler Status-Filter. Leer = alle.'],
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
        $svc = app(AngebotService::class);
        $treffer = $svc->paginateBrowser([
            'search' => '',
            'status' => (string) ($arguments['status'] ?? ''),
        ], $team, $perPage);

        return ToolResult::success([
            'total' => $treffer->total(),
            'page' => $treffer->currentPage(),
            'last_page' => $treffer->lastPage(),
            'per_page' => $treffer->perPage(),
            'status_werte' => $svc->statusWerte(),
            'angebote' => collect($treffer->items())->map(fn ($a) => [
                'id' => $a->id, 'name' => $a->name,
                'status' => $a->status instanceof \BackedEnum ? $a->status->value : $a->status,
                'occasion' => $a->occasion, 'personen' => $a->personen,
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'angebot', 'offer', 'list', 'katalog', 'paging'],
            'related_tools' => ['foodalchemist.angebote.SEARCH', 'foodalchemist.angebote.GET'],
            'examples' => ['Liste alle Angebote auf', 'Zeig mir alle offenen Angebote seitenweise'],
        ];
    }
}
