<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Services\PlanningCascadeService;

/**
 * Spec 53 / Paket D — Status-Antwort auf „Wie weit ist die Generierung?" ohne dass der User
 * die Lauf-ID kennt (anders als {@see PlanungKaskadeStatusGetTool}, das `run_id` verlangt und
 * für Voice deshalb allein nicht reicht). READ-ONLY, team-weit — die letzten 3 Kaskaden-Läufe
 * über ALLE Planungen des Teams, jeweils mit demselben Statusaggregat wie `planung_kaskade.GET`.
 */
class PlanungKaskadeLetzteGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.planung_kaskade.LETZTE';
    }

    public function getDescription(): string
    {
        return 'Die letzten 3 Planungs-Kaskaden-Läufe des Teams (neueste zuerst), READ-ONLY — je Lauf '
            . 'Status, Stufen-Aggregat und Handlungs-Hinweis, wie foodalchemist.planung_kaskade.GET. '
            . 'Nutzen bei „wie weit ist die Generierung?", „läuft meine Planung noch?" ohne bekannte run_id.';
    }

    public function getSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $cascade = app(PlanningCascadeService::class);
        $laeufe = FoodAlchemistCascadeRun::visibleToTeam($team)
            ->orderByDesc('id')
            ->limit(3)
            ->get(['id'])
            ->map(fn (FoodAlchemistCascadeRun $r) => $cascade->laufStatus($team, (int) $r->id))
            ->filter()
            ->values()
            ->all();

        return ToolResult::success(['laeufe' => $laeufe]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'planung', 'kaskade', 'voice', 'status'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.planung_kaskade.GET'],
            'examples' => ['Wie weit ist die Generierung?', 'Läuft meine Planung noch?'],
        ];
    }
}
