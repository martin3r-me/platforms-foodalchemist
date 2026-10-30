<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TrendEingangService;
use Platform\FoodAlchemist\Support\TrendVokabular as V;

/** Spec 93 A · Eingang des Trendradars lesen (systematisch erfasste Artikel, getrennt von der Pinnwand). */
class TrendEingangGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trend_eingang.GET';
    }

    public function getDescription(): string
    {
        return 'Liest den Eingang des Trendradars: Artikel aus den Quellen (Abruf) oder per MCP eingereicht — Titel, Link, Quelle, Datum, Teaser, '
            .'Thema, Status (neu|erschlossen|gebuendelt|verworfen|fehler). Filter status, quelle_id, suche; limit (Standard 100). '
            .'Die Pinnwand (Inspiration) liest foodalchemist.fundstuecke.GET.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'status' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(V::EINGANG_STATUS)]],
            'quelle_id' => ['type' => 'integer'],
            'suche' => ['type' => 'string'],
            'limit' => ['type' => 'integer', 'description' => '1–500, Standard 100'],
        ]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(TrendEingangService::class);
        $liste = $svc->liste($team, $arguments, (int) ($arguments['limit'] ?? 100));

        return ToolResult::success(['anzahl' => $liste->count(), 'zaehler' => $svc->zaehler($team),
            'eintraege' => $liste->map(fn ($e) => $svc->alsArray($e, $team))->values()->all()]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query', 'tags' => ['foodalchemist', 'trendradar', 'eingang', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => [],
            'related_tools' => ['foodalchemist.trend_eingang.POST', 'foodalchemist.trend_eingang.ENTSCHEIDEN', 'foodalchemist.trend_quellen.GET'],
            'examples' => ['Was ist neu im Trend-Eingang?', 'Zeig mir die verworfenen Artikel von Quelle 3.'],
        ];
    }
}
