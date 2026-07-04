<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\SalesRecipeService;

/** M8-01: Verkaufsrezepte durchsuchen (D-6, verkauf()-Scope inkl. Marge-Kopf). */
class VerkaufsrezepteSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.verkaufsrezepte.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Durchsucht die Verkaufsrezepte des Teams (auch über Marketing-Namen und '
            . 'Kunden-Wordings). Liefert id, name, sales_net, ek_total_eur, speisen_klasse.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10],
            ],
            'required' => ['q'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $treffer = app(SalesRecipeService::class)->paginateBrowser(
            ['search' => (string) $arguments['q']], $team, min(50, max(1, (int) ($arguments['limit'] ?? 10))),
        );

        return ToolResult::success([
            'total' => $treffer->total(),
            'verkaufsrezepte' => collect($treffer->items())->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'sales_net' => $r->sales_net,
                'ek_total_eur' => $r->ek_total_eur,
                'speisen_klasse' => $r->speisenKlasse?->label,
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'verkaufsrezept', 'rezept', 'verkauf', 'search'],
            'examples' => ['Suche Verkaufsrezepte mit Lachs'],
        ];
    }
}
