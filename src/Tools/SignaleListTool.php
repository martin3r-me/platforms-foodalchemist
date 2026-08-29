<?php

namespace Platform\FoodAlchemist\Tools;

use Illuminate\Pagination\Paginator;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Services\SignalService;

/**
 * #504: Vollständige, seiten-basierte Auflistung der Signale des Teams — ergänzt
 * signale.SEARCH (Cap 50, nur erste Seite). page/per_page-Paging über ALLE Status
 * (Default: alle) fürs komplette Enumerieren. Status setzen via signale.PUT.
 */
class SignaleListTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.signale.LIST';
    }

    public function getDescription(): string
    {
        return 'Listet die Signale (automatische Alerts) des Teams vollständig und seitenweise auf. '
            . 'Default: ALLE Status (nicht nur offene); optional status-/type-gefiltert. page/per_page-Paging '
            . '(last_page zum Weiterblättern). Liefert je Signal id, type, severity, status, title, created_at. '
            . 'Abschließen/Ignorieren via foodalchemist.signale.PUT.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'description' => 'Optionaler Status (offen/erledigt/ignoriert). Leer = alle (Default).'],
                // Aus dem Enum abgeleitet (seiteneffektfrei) — siehe SignaleSearchTool.
                'type' => ['type' => 'string', 'enum' => array_column(SignalTyp::cases(), 'value')],
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1, 'description' => 'Seitennummer (last_page aus der Vorantwort).'],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 100, 'description' => 'Seitengröße (max. 200).'],
                'outlet_id' => ['type' => 'integer', 'description' => 'Ebene 2: auf die Betriebs-Brille eingrenzen — nur Signale dieses Betriebs PLUS die betriebs-unabhängigen (Team-Core). Fehlt = alle Lanes. Betriebe via outlets.GET.'],
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

        // Ebene 2: optionale Betriebs-Lane. outlet_id gesetzt ⇒ Betrieb + Team-Core; sonst alle Lanes.
        $outlet = null;
        $nurLane = false;
        if (! empty($arguments['outlet_id'])) {
            $outlet = \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::where('team_id', $team->id)->find((int) $arguments['outlet_id']);
            if ($outlet === null) {
                return ToolResult::error('Betrieb nicht gefunden im Team.', 'NOT_FOUND');
            }
            $nurLane = true;
        }

        Paginator::currentPageResolver(fn () => $page);
        $svc = app(SignalService::class);
        // Default: alle Status (status='') — LIST enumeriert vollständig, nicht nur offene.
        $treffer = $svc->paginate([
            'status' => (string) ($arguments['status'] ?? ''),
            'type' => $arguments['type'] ?? null,
        ], $team, $perPage, $outlet, $nurLane);

        return ToolResult::success([
            'total' => $treffer->total(),
            'page' => $treffer->currentPage(),
            'last_page' => $treffer->lastPage(),
            'per_page' => $treffer->perPage(),
            'active_outlet_id' => $outlet?->id,
            'offen_gesamt' => $svc->offeneCount($team, $outlet, $nurLane),
            'signale' => collect($treffer->items())->map(fn ($s) => [
                'id' => $s->id,
                'type' => $s->type instanceof \BackedEnum ? $s->type->value : $s->type,
                'severity' => $s->severity instanceof \BackedEnum ? $s->severity->value : $s->severity,
                'status' => $s->status instanceof \BackedEnum ? $s->status->value : $s->status,
                'title' => $s->title,
                // Ebene 2: welche Lane — NULL = Team-Core (betriebs-unabhängig), sonst der Betrieb.
                'outlet_id' => $s->outlet_id !== null ? (int) $s->outlet_id : null,
                'created_at' => (string) $s->created_at,
                // V-009 (22·H4a), gleiche Projektion wie in `signale.SEARCH`.
                'zuletzt_gesehen' => $s->last_seen_at !== null ? (string) $s->last_seen_at : null,
                'gesehen_zaehler' => (int) ($s->seen_count ?? 1),
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'tags' => ['foodalchemist', 'signal', 'alert', 'list', 'katalog', 'paging'],
            'related_tools' => ['foodalchemist.signale.SEARCH', 'foodalchemist.signale.PUT'],
            'examples' => ['Liste alle Signale auf', 'Zeig mir alle erledigten Signale seitenweise'],
        ];
    }
}
