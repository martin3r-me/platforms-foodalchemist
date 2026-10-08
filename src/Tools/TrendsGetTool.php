<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\TrendService;
use Platform\FoodAlchemist\Support\TrendVokabular as V;

/** Spec 79 · Trendradar lesen: Liste mit Filtern oder ein Trend mit Belegen, Konfidenz-Begründung und Messungen. */
class TrendsGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trends.GET';
    }

    public function getDescription(): string
    {
        return 'Liest den Trendradar (Modell nach Sarah Spork). Ohne id: Liste, filterbar nach typ (trend|hype), ebene '
            .'(mode|konsum|mega|meta), kategorie (food|getraenke|deko|format), sparte (Filter, Trends ohne Sparte gelten für alle), status, suche, nur_befragung, nur_radar. '
            .'Mit id: ein Trend mit Belegen (Quelle, Link, Datei), Konfidenz-Begründung, Radar-Hindernis und Google-Trends-Messungen. '
            .'Zusätzlich das Vokabular (vokabular=true), damit Werte nicht geraten werden.';
    }

    public function getSchema(): array
    {
        $liste = fn (array $werte) => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys($werte)]];

        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'suche' => ['type' => 'string'],
                'typ' => $liste(V::TYPEN),
                'ebene' => $liste(V::EBENEN),
                'kategorie' => $liste(V::KATEGORIEN),
                'sparte' => $liste(V::SPARTEN) + ['description' => 'Für wen relevant; Trends ohne Sparte (= alle) sind immer dabei.'],
                'status' => $liste(V::STATUS),
                'nur_befragung' => ['type' => 'boolean'],
                'nur_radar' => ['type' => 'boolean'],
                'vokabular' => ['type' => 'boolean', 'description' => 'Erlaubte Werte mit Beschriftung mitliefern.'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(TrendService::class);
        if (! empty($arguments['id'])) {
            try {
                return ToolResult::success($svc->alsArray($svc->detail($team, (int) $arguments['id']), true));
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                return ToolResult::error('Trend nicht gefunden.', 'NOT_FOUND');
            }
        }
        $trends = $svc->liste($team, $arguments);
        $out = ['anzahl' => $trends->count(), 'trends' => $trends->map(fn ($t) => $svc->alsArray($t))->values()->all()];
        if (! empty($arguments['vokabular'])) {
            $out['vokabular'] = ['typ' => V::TYPEN, 'ebene' => V::EBENEN, 'kategorie' => V::KATEGORIEN, 'sparten' => V::SPARTEN, 'food_cluster' => V::FOOD_CLUSTER,
                'sicht' => V::SICHTEN, 'status' => V::STATUS, 'quelle' => V::QUELLEN, 'gartner_phase' => V::GARTNER_PHASEN,
                'konfidenz_regel' => 'hoch = Marktforschung/Kaufverhalten/Befragung + weitere Quelle; mittel = eine davon allein, Literatur oder zwei sonstige Quellen; niedrig = sonst. Instagram/Social Media/Google Trends bestätigen nie allein.'];
        }

        return ToolResult::success($out);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query', 'tags' => ['foodalchemist', 'trendradar', 'trends', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => [],
            'related_tools' => ['foodalchemist.trends.POST', 'foodalchemist.trends.PUT', 'foodalchemist.trend_belege.POST'],
            'examples' => ['Welche Trends stehen auf dem Radar?', 'Zeig mir alle Hypes im Food-Bereich.', 'Details und Belege zu Trend 12.'],
        ];
    }
}
