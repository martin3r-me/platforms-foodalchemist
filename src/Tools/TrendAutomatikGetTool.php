<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Trends\TrendAutomatikService;
use Platform\FoodAlchemist\Support\TrendAutomatikRegeln;

/** Spec 93 C · Einstellungen der Trend-Automatik lesen, auf Wunsch mit der Liste „Was die Automatik tun würde“ (schreibt nichts). */
class TrendAutomatikGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.trend_automatik.GET';
    }

    public function getDescription(): string
    {
        return 'Liest die Trend-Automatik des Teams: stufe (aus|bis_geprueft|radar), schatten (rechnet, schreibt keine Trends), regeln (wirksame Schwellen; '
            .'startwerte = Annahmen, nicht belegt), ki_budget_eur. plan=true liefert je automatischem Thema Kennzahlen, Entscheidung (Kandidat/Hype/Trend/Ebene/'
            .'Abklingen), geplante Aktion und Begründung — reine Rechnung, schreibt nichts, zweimal gerufen = dieselbe Liste.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => ['plan' => ['type' => 'boolean']]];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $svc = app(TrendAutomatikService::class);
        $out = $svc->einstellungen($team) + ['stufen' => TrendAutomatikRegeln::STUFEN,
            'hinweis' => 'Die Startwerte sind Annahmen des Kurators, keine belegten Zahlen — im Schatten-Modus kalibrieren.'];
        if (! empty($arguments['plan'])) {
            $out['plan'] = $svc->lauf($team, false);
        }

        return ToolResult::success($out);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query', 'tags' => ['foodalchemist', 'trendradar', 'automatik', 'read'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db', 'side_effects' => [],
            'related_tools' => ['foodalchemist.trend_automatik.PUT', 'foodalchemist.trend_automatik.LAUF'],
            'examples' => ['Was würde die Trend-Automatik gerade tun?'],
        ];
    }
}
