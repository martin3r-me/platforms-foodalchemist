<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
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
                'type' => ['type' => 'string', 'enum' => ['preis_anomalie', 'veraltete_preise', 'marge_unter_ziel', 'wareneinsatz_ueber_ziel', 'datenqualitaet_gp_la', 'naehrwert_plausi']],
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
