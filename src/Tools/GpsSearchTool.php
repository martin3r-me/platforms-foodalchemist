<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\GpService;

/** M8-01: Grundprodukte durchsuchen (D-3) — Tool → Service, team-scoped. */
class GpsSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.gps.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Durchsucht die Grundprodukte (GPs) des aktuellen Teams nach Name/Slug. '
            . 'Liefert id, name, status, hauptzutat_slug — Details via foodalchemist.gps.GET.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'description' => 'Suchbegriff (Name/Hauptzutat)'],
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
        $treffer = app(GpService::class)->paginate(
            ['search' => (string) $arguments['q']], $team, min(50, max(1, (int) ($arguments['limit'] ?? 10))),
        );

        return ToolResult::success([
            'total' => $treffer->total(),
            'gps' => collect($treffer->items())->map(fn ($gp) => [
                'id' => $gp->id, 'name' => $gp->name, 'status' => $gp->status,
                'hauptzutat_slug' => $gp->hauptzutat_slug,
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
            'tags' => ['foodalchemist', 'gp', 'grundprodukt', 'search'],
            'examples' => ['Suche Grundprodukte mit Zander', 'Welche GPs gibt es zu Kartoffel?'],
        ];
    }
}
