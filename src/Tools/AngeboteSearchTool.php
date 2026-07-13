<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\AngebotService;

/** Phase C: Angebote durchsuchen (CRM-gebundener Verkaufs-Einstieg). */
class AngeboteSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.angebote.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Durchsucht die Angebote des Teams (Name/Anlass, optional Status-Filter). Liefert id, name, '
            . 'status, personen, occasion — Details + Kalkulation via foodalchemist.angebote.GET.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'description' => 'Suchbegriff, leer = alle'],
                'status' => ['type' => 'string', 'description' => 'z. B. anfrage, angebot, gewonnen — leer = alle'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 15],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(AngebotService::class);
        $treffer = $svc->paginateBrowser([
            'search' => (string) ($arguments['q'] ?? ''),
            'status' => (string) ($arguments['status'] ?? ''),
        ], $team, min(50, max(1, (int) ($arguments['limit'] ?? 15))));

        return ToolResult::success([
            'total' => $treffer->total(),
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
            'tags' => ['foodalchemist', 'angebot', 'verkauf', 'anfrage', 'search'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.angebote.GET', 'foodalchemist.angebote.POST'],
            'examples' => ['Welche offenen Anfragen gibt es?'],
        ];
    }
}
