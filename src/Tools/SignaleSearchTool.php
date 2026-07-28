<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Services\SignalService;

/** Phase C: Signale (Daten-/Preis-/Margen-Alerts) durchsuchen. */
class SignaleSearchTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.signale.SEARCH';
    }

    public function getDescription(): string
    {
        return 'Listet Signale (automatische Alerts: Preis-Anomalie, veraltete Preise, Marge unter Ziel, '
            . 'Wareneinsatz über Ziel, Datenqualität, Nährwert-Plausi). Default: offene. '
            . 'Abschließen/Ignorieren via foodalchemist.signale.PUT.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'description' => 'offen (Default) | abgeschlossen | ignoriert | leer = alle'],
                // Aus dem Enum abgeleitet (seiteneffektfrei) — eine handgepflegte Liste war bereits
                // auf 7 von 14 Typen zurückgefallen und hätte mit Spec 21 weiter driftet.
                'type' => ['type' => 'string', 'enum' => array_column(SignalTyp::cases(), 'value')],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(SignalService::class);
        $treffer = $svc->paginate(array_filter([
            'status' => $arguments['status'] ?? null,
            'type' => $arguments['type'] ?? null,
        ], fn ($v) => $v !== null), $team, min(50, max(1, (int) ($arguments['limit'] ?? 20))));

        return ToolResult::success([
            'total' => $treffer->total(),
            'offen_gesamt' => $svc->offeneCount($team),
            'offen_nach_typ' => $svc->offeneNachTyp($team),
            'signale' => collect($treffer->items())->map(fn ($s) => [
                'id' => $s->id,
                'type' => $s->type instanceof \BackedEnum ? $s->type->value : $s->type,
                'severity' => $s->severity instanceof \BackedEnum ? $s->severity->value : $s->severity,
                'status' => $s->status instanceof \BackedEnum ? $s->status->value : $s->status,
                'title' => $s->title,
                'created_at' => (string) $s->created_at,
                // V-009 (22·H4a): erstmals gesehen ist `created_at`, zuletzt gesehen und wie
                // oft steht hier. Ein Befund mit hohem `gesehen_zaehler` ist ein Prozess-
                // Problem und kein Datenfehler — ohne die Zahl liest ein LLM beide gleich.
                'zuletzt_gesehen' => $s->last_seen_at !== null ? (string) $s->last_seen_at : null,
                'gesehen_zaehler' => (int) ($s->seen_count ?? 1),
            ])->all(),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'signal', 'alert', 'price', 'marge', 'datenqualität'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.signale.PUT'],
            'examples' => ['Welche offenen Signale gibt es?', 'Zeig mir Margen-Alerts'],
        ];
    }
}
