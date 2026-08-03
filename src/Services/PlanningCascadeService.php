<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Jobs\MaterializeConceptIdeaJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
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
 * gewählten Stufe abwärts. **P0/P1a orchestrieren `rezept`/`gericht`/`concept`** (je Depth-1, ein Step
 * je Go — Concept im Reuse-Assembler); der Gericht-Fan-out beim Concept (Erfinden) und `vollkaskade`
 * folgen in P1b/P3 und werfen bis dahin bewusst.
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
        // P0/P1a: rezept|gericht|concept sind orchestriert (je Depth-1). Die volle Kaskade
        // (Frame → mehrere Concepts → Fan-out) kommt in P3 — bis dahin ehrlich blocken.
        if ($scope === 'vollkaskade') {
            throw new RuntimeException('Scope «vollkaskade» ist noch nicht verfügbar (P3).');
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

        // Depth-1: genau ein Step (rezept|gericht → GenerateRecipeJob, concept → GenerateConceptJob).
        $step = FoodAlchemistCascadeRunStep::create([
            'team_id' => $team->id,
            'cascade_run_id' => $run->id,
            'parent_step_id' => null,
            'kind' => $scope,   // 'rezept' | 'gericht' | 'concept'
            'label' => Str::limit($brief, 120),
            'status' => 'running',
            'sort' => 0,
        ]);

        if ($scope === 'concept') {
            $this->dispatchConceptStep($team, $step, $brief, $session?->id, $creativeMode);
        } else {
            $this->dispatchRezeptStep($team, $step, $brief, $params, $scope === 'gericht', $vollAnreichern, $session?->id);
        }

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

    /** Dispatch der Konzept-Generierung für einen Step (Reuse-Assembler; im Erfinden-Modus fächert der Job auf). */
    private function dispatchConceptStep(Team $team, FoodAlchemistCascadeRunStep $step, string $brief, ?int $planningSessionId, string $creativeMode): void
    {
        $runId = (string) Str::uuid();
        $step->update(['generator_run_id' => $runId]);
        Cache::put(GenerateConceptJob::cacheKey($runId), ['status' => 'pending'], now()->addMinutes(self::RESULT_TTL_MIN));
        GenerateConceptJob::dispatch($runId, $team->id, (int) (\Illuminate\Support\Facades\Auth::id() ?? 0), $brief, null, $planningSessionId, $step->id, $creativeMode);
    }

    // ── P1b: Erfinden — Fan-out des Concepts in erfundene Gerichte ─────────

    /**
     * Fächert ein frisch erzeugtes Konzept in erfundene Gerichte auf (Erfinden-Modus). Je LEEREM Slot
     * (kein Gericht, kein Paket) lässt die KI eine Gericht-Idee erfinden ({@see IdeenService::kiDivergenzConcept},
     * EIN Call für alle Slots), ordnet Ideen den Slots der Reihe nach zu, legt je Idee einen Kind-Step
     * (kind=gericht, parent=Concept-Step) an und dispatcht {@see MaterializeConceptIdeaJob} (erdet + verdrahtet).
     *
     * Graceful: ohne LLM (Sandbox/Kill-Switch) wirft die Divergenz → 0 Ideen, 0 Kind-Steps; der Run geht
     * mit dem Konzept allein auf review. Wirft NIE (der Concept-Job fängt zusätzlich ab).
     */
    public function fanoutConceptInvention(Team $team, int $conceptStepId, int $conceptId, string $mode): void
    {
        $conceptStep = FoodAlchemistCascadeRunStep::find($conceptStepId);
        if ($conceptStep === null) {
            return;
        }
        $runId = (int) $conceptStep->cascade_run_id;

        $leere = FoodAlchemistConceptSlot::where('concept_id', $conceptId)
            ->whereNull('sales_recipe_id')
            ->whereNull('package_id')
            ->whereNotIn('type', ['text', 'spacer', 'header', 'header_preis'])
            ->orderBy('position')->orderBy('id')
            ->get();
        if ($leere->isEmpty()) {
            return;   // nichts zu erfinden — Reuse hat alle Slots gefüllt
        }

        try {
            $div = app(IdeenService::class)->kiDivergenzConcept($team, $conceptId, $leere->count());
        } catch (\Throwable) {
            return;   // KI nicht verfügbar → keine Erfindung, Konzept bleibt (graceful)
        }
        $ideen = is_array($div['angelegt'] ?? null) ? $div['angelegt'] : [];

        foreach (array_values($ideen) as $idx => $idee) {
            $slot = $leere[$idx] ?? null;
            if ($slot === null) {
                break;   // mehr Ideen als leere Slots — Rest ignorieren
            }
            $idee->update([
                'generation_status' => 'queued',
                'source_meta' => array_merge($idee->source_meta ?? [], ['target_concept_slot_id' => (int) $slot->id]),
            ]);
            $step = FoodAlchemistCascadeRunStep::create([
                'team_id' => $team->id,
                'cascade_run_id' => $runId,
                'parent_step_id' => $conceptStepId,
                'kind' => 'gericht',
                'label' => Str::limit((string) $idee->title, 120),
                'status' => 'running',
                'sort' => $idx + 1,
            ]);
            MaterializeConceptIdeaJob::dispatch($team->id, (int) (\Illuminate\Support\Facades\Auth::id() ?? 0), (int) $idee->id, (int) $step->id);
        }
    }

    /**
     * Worker-Logik (aus {@see MaterializeConceptIdeaJob}): erdet EINE erfundene Concept-Idee zu einem
     * echten VK-Gericht ({@see RecipeGeneratorService::generiere}, vkModus) und verdrahtet es in den
     * vorgemerkten leeren Slot ({@see ConceptService::fillSlot}); Lineage an die Idee, Rückmeldung an
     * den Kind-Step. Fehler (inkl. KI-Ausfall) → Step failed, Idee markiert — kein „halbes Wrack".
     */
    public function materialisiereConceptGericht(Team $team, int $ideaId, int $stepId): void
    {
        $idee = FoodAlchemistDishIdea::where('team_id', $team->id)->find($ideaId);
        if ($idee === null) {
            $this->markStepFailed($stepId, 'Idee nicht gefunden.');

            return;
        }
        $slotId = (int) ($idee->source_meta['target_concept_slot_id'] ?? 0);
        $beschreibung = trim(implode(' — ', array_filter([(string) $idee->title, (string) $idee->description]))) ?: (string) $idee->title;

        try {
            $gen = app(RecipeGeneratorService::class)->generiere($team, $beschreibung, [], null, true, 'plan_go');
            $recipe = $gen['recipe'] ?? null;
            if ($recipe === null) {
                throw new RuntimeException('Generierung lieferte kein Rezept.');
            }
            if ($slotId > 0) {
                app(ConceptService::class)->fillSlot($team, $slotId, ['sales_recipe_id' => (int) $recipe->id, 'type' => 'gericht']);
            }
            $idee->update([
                'generation_status' => 'erstellt',
                'status' => 'freigegeben',
                'generated_recipe_id' => (int) $recipe->id,
                'materialized_at' => now(),
                'materialized_ref' => ['concept_slot_id' => $slotId, 'recipe_id' => (int) $recipe->id],
                'source_meta' => array_merge($idee->source_meta ?? [], ['erdung' => 'ki_generiert', 'original_titel' => (string) $idee->title]),
            ]);
            $this->markStepDone($stepId, 'recipe', (int) $recipe->id);
        } catch (\Throwable $e) {
            $idee->update(['generation_status' => 'fehlgeschlagen', 'source_meta' => array_merge($idee->source_meta ?? [], ['generation_fehler' => mb_substr($e->getMessage(), 0, 500)])]);
            $this->markStepFailed($stepId, $e->getMessage());
        }
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
     * Run-Status aus den Steps ableiten:
     * - ein Step läuft (queued|running)                       → `running`
     * - ein Step ist erzeugt, aber unentschieden (done)       → `review` (Gate 2 offen)
     * - alles entschieden, mind. ein freigegeben|skipped       → `done`
     * - alles entschieden, nur verworfen|failed                → `failed`
     */
    public function recomputeRunStatus(int $runId): void
    {
        $run = FoodAlchemistCascadeRun::find($runId);
        if ($run === null) {
            return;
        }
        $steps = $run->steps()->get(['status']);
        if ($steps->whereIn('status', ['queued', 'running'])->count() > 0) {
            if ($run->status !== 'running') {
                $run->update(['status' => 'running']);
            }

            return;
        }
        if ($steps->where('status', 'done')->count() > 0) {
            $run->update(['status' => 'review']);

            return;
        }
        $positiv = $steps->whereIn('status', ['freigegeben', 'skipped'])->count();
        $run->update(['status' => $positiv > 0 ? 'done' : 'failed']);
    }

    // ── Freigabe / Verwerfen (Gate 2 — inline im Editor) ───────────────────

    /**
     * Step freigeben: das Draft-Artefakt live setzen (Rezept → approved, Concept → active) über die
     * sanktionierten Services, Step → `freigegeben`, Run neu bewerten. Nur `done`-Steps sind freigebbar.
     */
    public function gibStepFrei(Team $team, int $stepId): void
    {
        $step = $this->ownedStep($team, $stepId);
        if ($step->status !== 'done') {
            return;
        }
        if ($step->ref_id !== null) {
            if ($step->ref_type === 'recipe') {
                app(RecipeService::class)->setStatus($team, (int) $step->ref_id, 'approved');
            } elseif ($step->ref_type === 'concept') {
                app(ConceptService::class)->setStatus($team, (int) $step->ref_id, 'active');
            }
        }
        $step->update(['status' => 'freigegeben']);
        $this->recomputeRunStatus((int) $step->cascade_run_id);
    }

    /**
     * Step verwerfen: das Draft-Artefakt soft-deleten (kein DB-Müll), Step → `verworfen`, Run neu
     * bewerten. Greift bei `done` (generiert) und `failed` (Teil-Wrack aufräumen).
     */
    public function verwirfStep(Team $team, int $stepId): void
    {
        $step = $this->ownedStep($team, $stepId);
        if (! in_array($step->status, ['done', 'failed'], true)) {
            return;
        }
        if ($step->ref_id !== null) {
            if ($step->ref_type === 'recipe') {
                FoodAlchemistRecipe::where('team_id', $team->id)->whereKey($step->ref_id)->delete();
            } elseif ($step->ref_type === 'concept') {
                FoodAlchemistConcept::where('team_id', $team->id)->whereKey($step->ref_id)->delete();
            }
        }
        $step->update(['status' => 'verworfen']);
        $this->recomputeRunStatus((int) $step->cascade_run_id);
    }

    /** Bulk-Freigabe aller noch offenen (done) Steps eines Laufs. */
    public function gibRunFrei(Team $team, int $runId): void
    {
        $run = $this->lauf($team, $runId);
        if ($run === null) {
            return;
        }
        foreach ($run->steps->where('status', 'done') as $s) {
            $this->gibStepFrei($team, (int) $s->id);
        }
    }

    /** Bulk-Verwerfen aller noch offenen (done|failed) Steps eines Laufs. */
    public function verwirfRun(Team $team, int $runId): void
    {
        $run = $this->lauf($team, $runId);
        if ($run === null) {
            return;
        }
        foreach ($run->steps->whereIn('status', ['done', 'failed']) as $s) {
            $this->verwirfStep($team, (int) $s->id);
        }
    }

    private function ownedStep(Team $team, int $stepId): FoodAlchemistCascadeRunStep
    {
        $step = FoodAlchemistCascadeRunStep::visibleToTeam($team)->findOrFail($stepId);
        if (! $step->isOwnedBy($team)) {
            throw new RuntimeException('Geerbter Kaskaden-Step — Freigabe nur durchs Besitzer-Team (D1).');
        }

        return $step;
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
