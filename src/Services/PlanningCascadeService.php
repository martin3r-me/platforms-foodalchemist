<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use RuntimeException;

/**
 * Der geteilte Kaskaden-Motor (Planungs-Kaskade). EIN Einstieg für alle Flächen: {@see starteKaskade}.
 *
 * Prinzip „generalisieren statt neu bauen": der Motor orchestriert die bestehenden Erzeugungs-Services
 * (P0: {@see GenerateRecipeJob} → {@see RecipeGeneratorService}) und trackt sie über
 * {@see FoodAlchemistCascadeRun}/{@see FoodAlchemistCascadeRunStep}. Er erzeugt NUR Drafts — die
 * Freigabe an eine Live-Ausgabe ist das zweite Gate (Sammel-Review, P2).
 *
 * Tiefen-Leiter (`scope`): `rezept` ⊂ `gericht` ⊂ `concept` ⊂ `vollkaskade`; der Motor läuft von der
 * gewählten Stufe abwärts. **P0 orchestriert `rezept`/`gericht`** (Depth-1, ein Step je Go); `concept`
 * und `vollkaskade` folgen in P1/P3 und werfen bis dahin bewusst.
 */
class PlanningCascadeService
{
    /** Async-Result via Cache (Job-Vertrag) — Minuten, bis der Worker den Step abschließt. */
    private const RESULT_TTL_MIN = 15;

    /**
     * Startet einen Kaskaden-Lauf und gibt ihn zurück (Status `running`). Die eigentliche Generierung
     * läuft asynchron im Queue-Job; die Fläche pollt den Run/seine Steps.
     *
     * @param  array{brief?:string, params?:array<string,mixed>, voll_anreichern?:bool, created_via?:string}  $optionen
     */
    public function starteKaskade(
        Team $team,
        string $scope,
        ?FoodAlchemistPlanningSession $session,
        string $creativeMode,
        array $optionen = [],
    ): FoodAlchemistCascadeRun {
        if (! in_array($scope, FoodAlchemistCascadeRun::SCOPES, true)) {
            throw new RuntimeException("Unbekannter Kaskaden-Scope «{$scope}».");
        }
        if (! in_array($creativeMode, FoodAlchemistPlanningSession::CREATIVE_MODES, true)) {
            $creativeMode = 'voll_kreativ';
        }
        // P0: nur die beiden Blatt-Stufen sind orchestriert. Die Concept-/Voll-Kaskade
        // (Erfindung + Fan-out) kommt in P1/P3 — bis dahin ehrlich blocken statt still nichts tun.
        if (! in_array($scope, ['rezept', 'gericht'], true)) {
            throw new RuntimeException("Scope «{$scope}» ist noch nicht verfügbar (P1/P3).");
        }

        $brief = trim((string) ($optionen['brief'] ?? ''));
        if ($brief === '' && $session !== null) {
            $brief = $this->briefAusSession($session);
        }
        if ($brief === '') {
            throw new RuntimeException('Kein Brief für die Kaskade — Titel/Brief/Analyse fehlen.');
        }

        $params = is_array($optionen['params'] ?? null) ? $optionen['params'] : [];
        $vollAnreichern = (bool) ($optionen['voll_anreichern'] ?? true);

        $run = FoodAlchemistCascadeRun::create([
            'team_id' => $team->id,
            'planning_session_id' => $session?->id,
            'scope' => $scope,
            'creative_mode' => $creativeMode,
            'brief' => $brief,
            'params' => $params !== [] ? $params : null,
            'status' => 'running',
            'created_via' => (string) ($optionen['created_via'] ?? 'plan_go'),
        ]);

        // Depth-1: genau ein Rezept-/Gericht-Step, der einen GenerateRecipeJob umhüllt.
        $vkModus = $scope === 'gericht';
        $step = FoodAlchemistCascadeRunStep::create([
            'team_id' => $team->id,
            'cascade_run_id' => $run->id,
            'parent_step_id' => null,
            'kind' => $scope,   // 'rezept' | 'gericht'
            'label' => Str::limit($brief, 120),
            'status' => 'running',
            'sort' => 0,
        ]);

        $this->dispatchRezeptStep($team, $step, $brief, $params, $vkModus, $vollAnreichern, $session?->id);

        return $run;
    }

    /** Dispatch der Rezept-/Gericht-Generierung für einen Step (spiegelt HatGeneratorLauf::starteLauf). */
    private function dispatchRezeptStep(
        Team $team,
        FoodAlchemistCascadeRunStep $step,
        string $brief,
        array $params,
        bool $vkModus,
        bool $vollAnreichern,
        ?int $planningSessionId,
    ): void {
        $runId = (string) Str::uuid();
        // Parameter-Bündel: Lineage (planning_session_id, vom Job an verknuepfeArtefakt) + der
        // Rückkanal an diesen Step (cascade_step_id → Job meldet Ergebnis/Fehler hierher zurück).
        $jobParams = $params;
        if ($planningSessionId !== null) {
            $jobParams['planning_session_id'] = $planningSessionId;
        }
        $jobParams['cascade_step_id'] = $step->id;

        $step->update(['generator_run_id' => $runId]);
        Cache::put(GenerateRecipeJob::cacheKey($runId), ['status' => 'pending'], now()->addMinutes(self::RESULT_TTL_MIN));
        GenerateRecipeJob::dispatch($runId, $team->id, (int) (\Illuminate\Support\Facades\Auth::id() ?? 0), $brief, $jobParams, $vkModus, $vollAnreichern);
    }

    // ── Rückkanal aus dem Job (läuft im Queue-Worker) ──────────────────────

    /** Step erfolgreich: erzeugtes Artefakt festhalten, dann Run-Status neu bestimmen. */
    public function markStepDone(int $stepId, string $refType, int $refId): void
    {
        $step = FoodAlchemistCascadeRunStep::find($stepId);
        if ($step === null) {
            return;
        }
        $step->update(['status' => 'done', 'ref_type' => $refType, 'ref_id' => $refId, 'error' => null]);
        $this->recomputeRunStatus((int) $step->cascade_run_id);
    }

    /** Step fehlgeschlagen: Fehler festhalten (Artefakt bleibt ggf. teilweise erzeugt), Run neu bewerten. */
    public function markStepFailed(int $stepId, string $error): void
    {
        $step = FoodAlchemistCascadeRunStep::find($stepId);
        if ($step === null) {
            return;
        }
        $step->update(['status' => 'failed', 'error' => Str::limit($error, 500, '')]);
        $this->recomputeRunStatus((int) $step->cascade_run_id);
    }

    /**
     * Run-Status aus den Steps ableiten: solange ein Step läuft → `running`; sonst wenn mindestens
     * ein Step fertig ist → `review` (Drafts warten aufs Gate); wenn alle fehlgeschlagen → `failed`.
     */
    public function recomputeRunStatus(int $runId): void
    {
        $run = FoodAlchemistCascadeRun::find($runId);
        if ($run === null) {
            return;
        }
        $steps = $run->steps()->get(['status']);
        $offen = $steps->whereIn('status', ['queued', 'running'])->count();
        if ($offen > 0) {
            if ($run->status !== 'running') {
                $run->update(['status' => 'running']);
            }

            return;
        }
        $fertig = $steps->whereIn('status', ['done', 'skipped'])->count();
        $run->update(['status' => $fertig > 0 ? 'review' : 'failed']);
    }

    // ── Abfragen (für die Fläche) ─────────────────────────────────────────

    /** Neuester Lauf einer Planung (für das Cockpit-Polling), null wenn keiner. */
    public function letzterLauf(Team $team, int $planningSessionId): ?FoodAlchemistCascadeRun
    {
        return FoodAlchemistCascadeRun::visibleToTeam($team)
            ->where('planning_session_id', $planningSessionId)
            ->orderByDesc('id')
            ->first();
    }

    /** Ein team-sichtbarer Lauf inkl. Steps (oder null). */
    public function lauf(Team $team, int $runId): ?FoodAlchemistCascadeRun
    {
        return FoodAlchemistCascadeRun::visibleToTeam($team)->with('steps')->find($runId);
    }

    /** Brief für die Erzeugung: Session-Brief (Fallback Titel) + Analyse-Auszug (spiegelt Planung::goBrief). */
    private function briefAusSession(FoodAlchemistPlanningSession $session): string
    {
        $brief = trim((string) $session->brief);
        $analyse = trim((string) $session->analysis);
        $text = $brief !== '' ? $brief : (string) $session->title;
        if ($analyse !== '') {
            $text .= "\n\nKontext:\n" . mb_substr($analyse, 0, 800);
        }

        return trim($text);
    }
}
