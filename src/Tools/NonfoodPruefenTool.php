<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Pagination\Paginator;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SupplierItemService;
use Platform\FoodAlchemist\Support\NonFoodRegeln;

/** Non-Food-Prüfliste: Artikel, bei denen Regel und KI nicht sicher entscheiden konnten (oder alle als Non-Food erkannten). Nur lesend. */
class NonfoodPruefenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.nonfood.PRUEFLISTE';
    }

    public function getDescription(): string
    {
        return 'Listet Lieferantenartikel nach ihrem Non-Food-Urteil seitenweise auf. art = pruefen (Standard): Artikel, bei denen Regel und KI '
            .'unsicher waren und ein Mensch entscheiden soll; nonfood: als Non-Food entschieden (Regel, KI oder von Hand); food: alle ohne entschiedenes Non-Food. '
            .'Je Artikel id, Bezeichnung, Lieferant, Urteil (ist_nonfood: true/false/null = unklar), Herkunft (regel/ki/manuell), Konfidenz, Begründung, Unterkategorie. '
            .'Entscheiden mit foodalchemist.nonfood.SET. Unterkategorien: '.implode(', ', NonFoodRegeln::unterkategorien()).'.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'art' => ['type' => 'string', 'enum' => ['pruefen', 'nonfood', 'food'], 'default' => 'pruefen'],
            'supplier_id' => ['type' => 'integer', 'description' => 'nur Artikel dieses Lieferanten'],
            'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $art = (string) ($arguments['art'] ?? 'pruefen');
        if (! in_array($art, ['pruefen', 'nonfood', 'food'], true)) {
            return ToolResult::error('art muss pruefen, nonfood oder food sein.', 'VALIDATION_ERROR');
        }
        $page = max(1, (int) ($arguments['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($arguments['per_page'] ?? 50)));
        $supplierId = (int) ($arguments['supplier_id'] ?? 0);
        Paginator::currentPageResolver(fn () => $page);

        $svc = app(SupplierItemService::class);
        $treffer = $supplierId > 0
            ? $svc->paginateForSupplier($team, $supplierId, ['art' => $art, 'onlyActive' => false], $perPage)
            : $svc->searchGlobal($team, '', ['art' => $art, 'onlyActive' => false], $perPage);

        return ToolResult::success([
            'art' => $art,
            'total' => $treffer->total(), 'page' => $treffer->currentPage(), 'last_page' => $treffer->lastPage(), 'per_page' => $treffer->perPage(),
            'artikel' => collect($treffer->items())->map(function ($i) {
                $n = $i->relationLoaded('nonfood') ? $i->nonfood : null;

                return [
                    'id' => $i->id, 'designation' => $i->designation, 'lieferant' => $i->supplier?->name,
                    'ist_nonfood' => $n?->ist_nonfood, 'quelle' => $n?->quelle, 'konfidenz' => $n?->konfidenz !== null ? (float) $n->konfidenz : null,
                    'begruendung' => $n?->begruendung, 'subkategorie' => $n?->subkategorie, 'pruefung_offen' => (bool) $n?->pruefung_offen,
                ];
            })->values()->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query', 'tags' => ['foodalchemist', 'nonfood', 'pruefliste', 'artikel'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe', 'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'side_effects' => [], 'related_tools' => ['foodalchemist.nonfood.SET', 'foodalchemist.artikel.SEARCH'],
            'examples' => ['Welche Artikel muss ich noch als Food oder Non-Food prüfen?', 'Zeig alle als Non-Food erkannten Artikel von Hanos.'],
        ];
    }
}
