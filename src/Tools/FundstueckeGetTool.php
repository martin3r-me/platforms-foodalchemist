<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendBeleg;
use Platform\FoodAlchemist\Services\TrendService;

/** Spec 79 · Inspirations-Pinnwand lesen: Fundstücke des Teams und Häufungen je Schlagwort. */
class FundstueckeGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.fundstuecke.GET';
    }

    public function getDescription(): string
    {
        return 'Liest die Inspirations-Pinnwand des Trendradars: Fundstücke (Instagram-Post, Foto, Link — Beobachtungen, '
            .'noch kein Trend). ansicht offen (Standard, keinem Trend zugeordnet) | zugeordnet | alle, suche, schlagwort. '
            .'Liefert zusätzlich Häufungen offener Fundstücke je Schlagwort (ab 2) — Kandidaten für einen Hype oder Trend.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'ansicht' => ['type' => 'string', 'enum' => ['offen', 'zugeordnet', 'alle']],
            'suche' => ['type' => 'string'],
            'schlagwort' => ['type' => 'string'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(TrendService::class);
        $liste = $svc->fundstuecke($team, (string) ($arguments['ansicht'] ?? 'offen'), (string) ($arguments['suche'] ?? ''), $arguments['schlagwort'] ?? null);

        return ToolResult::success([
            'anzahl' => $liste->count(),
            'fundstuecke' => $liste->map(fn (FoodAlchemistTrendBeleg $b) => [
                'id' => $b->id, 'titel' => $b->titel, 'quelle' => $b->quelle, 'url' => $b->url, 'notiz' => $b->notiz,
                'fundort' => $b->fundort, 'beobachtet_am' => $b->beobachtet_am?->toDateString(), 'schlagworte' => $b->schlagworte ?? [],
                'datei' => $b->datei_name, 'datei_url' => $svc->dateiUrl($b),
                'trend' => $b->trend ? ['id' => $b->trend->id, 'name' => $b->trend->name] : null,
            ])->values()->all(),
            'haeufungen' => $svc->haeufungen($team),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query', 'tags' => ['foodalchemist', 'trendradar', 'inspiration', 'fundstueck', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => [],
            'related_tools' => ['foodalchemist.fundstuecke.POST', 'foodalchemist.fundstuecke.PUT', 'foodalchemist.trends.POST'],
            'examples' => ['Was liegt Neues in der Inspirations-Pinnwand?', 'Gibt es Häufungen bei den Fundstücken?'],
        ];
    }
}
