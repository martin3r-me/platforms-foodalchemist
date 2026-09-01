<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Services\PlanningCascadeService;

/**
 * Gestufte Kaskade: startet den Gericht-Fan-out eines Concepts NACH dessen Freigabe.
 *
 * Der Concept-Step trägt die aufgeschobenen Fan-out-Args in `deferred.fanout` (mode/trend_doc_id/
 * planning_session_id). Die LLM-Divergenz ({@see PlanningCascadeService::fanoutConceptInvention})
 * läuft hier im Worker (nicht im Web-Request der Freigabe). Danach Run-Status neu bestimmen.
 */
class FanoutConceptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries = 1;

    public function __construct(
        public int $teamId,
        public int $userId,
        public int $cascadeStepId,
    ) {}

    public function handle(PlanningCascadeService $cascade): void
    {
        $team = Team::find($this->teamId);
        $step = FoodAlchemistCascadeRunStep::find($this->cascadeStepId);
        if ($team === null || $step === null || $step->ref_id === null) {
            return;
        }
        if ($cascade->istAbgebrochen((int) $step->cascade_run_id)) {
            return;
        }
        $user = User::find($this->userId);
        if ($user !== null) {
            Auth::login($user);   // Team-Kontext für AiGatewayService (Divergenz-Call)
        }

        $d = is_array($step->deferred['fanout'] ?? null) ? $step->deferred['fanout'] : [];
        $mode = (string) ($d['mode'] ?? 'voll_kreativ');
        $trendDocId = isset($d['trend_doc_id']) ? (int) $d['trend_doc_id'] : null;
        $planningSessionId = isset($d['planning_session_id']) ? (int) $d['planning_session_id'] : null;

        try {
            $cascade->fanoutConceptInvention($team, (int) $step->id, (int) $step->ref_id, $mode, $trendDocId, $planningSessionId);
        } finally {
            $step->update(['deferred' => null]);
            // 0 Gerichte (keine leeren Slots / kein LLM) → Run bleibt sonst auf „running" hängen.
            $cascade->recomputeRunStatus((int) $step->cascade_run_id);
        }
    }

    /**
     * Job-Tod (Timeout/Fatal in der Divergenz) → der Fehler wird sichtbar gemacht (Fehler-Transparenz,
     * Etappe 8), ABER der Concept-Step NICHT auf `failed` gekippt (#124): das Concept-Rezept ist zu
     * diesem Zeitpunkt längst angelegt + freigegeben — nur die automatische Gericht-Erfindung crashte.
     * {@see PlanningCascadeService::markFanoutFailed} lässt den freigegebenen Step stehen und hält den
     * Grund in `deferred.fanout_error` fest (Cockpit: „Auto-Gericht-Erfindung fehlgeschlagen").
     */
    public function failed(\Throwable $e): void
    {
        app(PlanningCascadeService::class)->markFanoutFailed($this->cascadeStepId, 'Gericht-Fan-out abgebrochen: ' . $e->getMessage());
    }
}
