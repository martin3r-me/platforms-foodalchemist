<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\EnrichRecipeJob;
use Platform\FoodAlchemist\Jobs\FanoutConceptJob;
use Platform\FoodAlchemist\Jobs\GenerateConceptJob;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Jobs\MaterializeConceptIdeaJob;
use Platform\FoodAlchemist\Jobs\MaterializeSpeiseplanCellJob;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
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

    /** Deckel gegen Runaway-Kosten: max. Zellen (= KI-Gericht-Generierungen) je Speiseplan-Voll-Kaskade. */
    private const SPEISEPLAN_MAX_ZELLEN = 30;

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
        // Voll-Kaskade (P3+): Ausgabe → Gerichte/Concepts. foodbook|speisekarte = 1 Concept je Slot;
        // speiseplan (P5) = ein Gericht je leerer Zyklus-Zelle (Zeitachse, kein Concept-Zwischenschritt).
        if ($scope === 'vollkaskade') {
            if ((string) ($optionen['owner_type'] ?? '') === 'speiseplan') {
                return $this->starteSpeiseplanVollkaskade($team, $session, $creativeMode, $optionen);
            }

            return $this->starteVollkaskade($team, $session, $creativeMode, $optionen);
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
        // Gate pro Ebene: die Cockpit-Scopes (rezept|gericht|concept) laufen gestuft — jede Ebene hält an,
        // bis sie freigegeben wird (dann startet die nächste). Opt-out via optionen['staged']=false.
        $staged = (bool) ($optionen['staged'] ?? true);

        $run = FoodAlchemistCascadeRun::create([
            'team_id' => $team->id,
            'planning_session_id' => $session?->id,
            'scope' => $scope,
            'creative_mode' => $creativeMode,
            'brief' => $brief,
            'params' => $params !== [] ? $params : null,
            'status' => 'running',
            'staged' => $staged,
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
            // Im gestuften Lauf schiebt der Root-Step (Basisrezept/Gericht) seine Kinder auf bis zur Freigabe.
            $this->dispatchRezeptStep($team, $step, $brief, $params, $scope === 'gericht', $vollAnreichern, $session?->id, $staged);
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
        bool $staged = false,
    ): void {
        $runId = (string) Str::uuid();
        // Parameter-Bündel: Lineage (planning_session_id, vom Job an verknuepfeArtefakt) + der
        // Rückkanal an diesen Step (cascade_step_id → Job meldet Ergebnis/Fehler hierher zurück).
        $jobParams = $params;
        if ($planningSessionId !== null) {
            $jobParams['planning_session_id'] = $planningSessionId;
        }
        $jobParams['cascade_step_id'] = $step->id;
        // Gestuft: der Root-Step schiebt seine Sub-Rezepte auf (afterGenerated legt sie in `deferred` ab
        // statt zu dispatchen); freigegeben wird die nächste Ebene erst bei der Freigabe. Sonst eager.
        $jobParams['auto_dependencies'] = ! $staged;
        $jobParams['_defer_children'] = $staged;

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

    // ── P3/P4: Voll-Kaskade — Ausgabe-Frame → 1 Concept je Slot ───────────

    /**
     * Voll-Kaskade aus einem Ausgabe-Frame (P3 Foodbook, P4 Speisekarte): je Frame-Slot ein Concept-Step
     * + eigener {@see GenerateConceptJob} (der ans Ausgabe-Kapitel/-Rubrik hängt und danach in Gerichte
     * fächert). Owner + ID kommen über `$optionen['owner_type']`/`['owner_id']`; die Slots werden owner-
     * spezifisch in Container (Kapitel/Rubrik) materialisiert. Ohne Frame/Slots → ehrlicher Fehler.
     *
     * @param  array{owner_type?:string, owner_id?:int, created_via?:string}  $optionen
     */
    private function starteVollkaskade(Team $team, ?FoodAlchemistPlanningSession $session, string $creativeMode, array $optionen): FoodAlchemistCascadeRun
    {
        $ownerType = (string) ($optionen['owner_type'] ?? '');
        $ownerId = (int) ($optionen['owner_id'] ?? 0);
        // P3 foodbook, P4 speisekarte (je 1 Concept/Slot). Der Speiseplan (P5) läuft über einen eigenen Zell-Pfad.
        if (! in_array($ownerType, ['foodbook', 'speisekarte'], true) || $ownerId <= 0) {
            throw new RuntimeException('Voll-Kaskade braucht owner_type=foodbook|speisekarte + owner_id.');
        }

        $frame = app(PlanningFrameService::class)->find($ownerType, $ownerId);
        if ($frame === null || $frame->slots()->count() === 0) {
            throw new RuntimeException('Ausgabe hat noch kein Planungs-Gerüst — erst Kickoff/Struktur anlegen.');
        }

        $run = FoodAlchemistCascadeRun::create([
            'team_id' => $team->id,
            'planning_session_id' => $session?->id,
            'scope' => 'vollkaskade',
            'creative_mode' => $creativeMode,
            'brief' => 'Voll-Kaskade ' . $ownerType . ' #' . $ownerId,
            'status' => 'running',
            'source_owner_type' => $ownerType,
            'source_owner_id' => $ownerId,
            'created_via' => (string) ($optionen['created_via'] ?? 'plan_go'),
        ]);

        $slots = $this->vollkaskadeSlots($team, $ownerType, $ownerId, $frame);
        $idx = 0;
        foreach ($slots as [$slot, $containerId]) {
            $step = FoodAlchemistCascadeRunStep::create([
                'team_id' => $team->id,
                'cascade_run_id' => $run->id,
                'parent_step_id' => null,
                'kind' => 'concept',
                'label' => Str::limit((string) ($slot->label ?: 'Konzept'), 120),
                'status' => 'running',
                'sort' => $idx,
            ]);
            $runId = (string) Str::uuid();
            $step->update(['generator_run_id' => $runId]);
            Cache::put(GenerateConceptJob::cacheKey($runId), ['status' => 'pending'], now()->addMinutes(self::RESULT_TTL_MIN));
            GenerateConceptJob::dispatch(
                $runId, $team->id, (int) (\Illuminate\Support\Facades\Auth::id() ?? 0),
                $this->slotBrief($ownerType, $ownerId, $slot), (string) ($slot->label ?: null),
                $session?->id, $step->id, $creativeMode, false, false, $ownerType, $containerId
            );
            $idx++;
        }
        if ($idx === 0) {
            $run->update(['status' => 'failed']);   // Frame ohne verwertbare Slots
        }

        return $run;
    }

    /**
     * Slots eines Ausgabe-Frames in Container materialisieren + als [slot, containerId] zurückgeben.
     * foodbook: `strukturAusGeruest` legt je Slot ein Kapitel an (chapter_id). speisekarte: je Slot eine
     * Rubrik (idempotent per Titel).
     *
     * @return list<array{0: \Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameSlot, 1: int}>
     */
    private function vollkaskadeSlots(Team $team, string $ownerType, int $ownerId, $frame): array
    {
        $out = [];
        if ($ownerType === 'foodbook') {
            app(FoodbookService::class)->strukturAusGeruest($team, $ownerId);   // Slots → Kapitel (idempotent)
            $frame->load('slots');
            foreach ($frame->slots as $slot) {
                if ($slot->chapter_id !== null) {
                    $out[] = [$slot, (int) $slot->chapter_id];
                }
            }

            return $out;
        }
        if ($ownerType === 'speisekarte') {
            $svc = app(SpeisekarteService::class);
            $frame->load('slots');
            foreach ($frame->slots as $slot) {
                $out[] = [$slot, $svc->rubrikFuerSlot($team, $ownerId, (string) ($slot->label ?: 'Rubrik'))];
            }

            return $out;
        }

        return $out;
    }

    /** Kompakter Brief je Slot für die Concept-Erzeugung (Rolle/Label + Ziele + Preis-Anker). */
    private function slotBrief(string $ownerType, int $ownerId, $slot): string
    {
        $teile = ['[' . ($slot->label ?: 'Gang') . ']'];
        if ((int) $slot->target_count > 0) {
            $teile[] = 'Zielanzahl Gerichte: ' . (int) $slot->target_count;
        }
        if ($slot->price_anchor !== null) {
            $teile[] = 'Preis-Anker p.P.: ' . $slot->price_anchor . ' €';
        }
        if ($slot->note !== null && trim((string) $slot->note) !== '') {
            $teile[] = trim((string) $slot->note);
        }

        return 'Konzept für die Rolle ' . implode(' — ', $teile) . '.';
    }

    // ── P5: Speiseplan-Voll-Kaskade — ein Gericht je leerer Zyklus-Zelle ──

    /**
     * Speiseplan-Voll-Kaskade (P5): füllt leere Zellen des Zyklus (cycle_weeks × Mo–Fr × Mittag × Linien) mit
     * erfundenen Gerichten. Anders als Foodbook/Speisekarte (Slot → Concept) hält eine Zelle EIN Gericht — je
     * leerer Zelle ein Gericht-Step + {@see MaterializeSpeiseplanCellJob} (generiert + trägt via addEintrag ein).
     * Gedeckelt ({@see SPEISEPLAN_MAX_ZELLEN}) gegen Runaway-Kosten; die Zahl der übersprungenen Zellen steht
     * im Run (`params.gedeckelt_zellen_offen`) — kein stiller Deckel.
     */
    private function starteSpeiseplanVollkaskade(Team $team, ?FoodAlchemistPlanningSession $session, string $creativeMode, array $optionen): FoodAlchemistCascadeRun
    {
        $planId = (int) ($optionen['owner_id'] ?? 0);
        $plan = $planId > 0 ? FoodAlchemistSpeiseplan::visibleToTeam($team)->with(['lines', 'entries'])->find($planId) : null;
        if ($plan === null) {
            throw new RuntimeException('Speiseplan nicht gefunden.');
        }
        if ($plan->lines->isEmpty()) {
            throw new RuntimeException('Speiseplan hat keine Menü-Linien — erst Linien anlegen.');
        }

        $run = FoodAlchemistCascadeRun::create([
            'team_id' => $team->id,
            'planning_session_id' => $session?->id,
            'scope' => 'vollkaskade',
            'creative_mode' => $creativeMode,
            'brief' => 'Voll-Kaskade speiseplan #' . $planId,
            'status' => 'running',
            'source_owner_type' => 'speiseplan',
            'source_owner_id' => $planId,
            'created_via' => (string) ($optionen['created_via'] ?? 'plan_go'),
        ]);

        $start = \Illuminate\Support\Carbon::parse($plan->start_date ?? now())->startOfWeek();   // Montag
        $weeks = max(1, (int) ($plan->cycle_weeks ?? 1));
        $meal = 'mittag';
        $belegt = [];
        foreach ($plan->entries as $e) {
            if ($e->entry_date !== null) {
                $belegt[$e->entry_date->format('Y-m-d') . '|' . $e->meal . '|' . (int) $e->line_id] = true;
            }
        }

        $idx = 0;
        $offen = 0;
        foreach (range(1, $weeks) as $week) {
            foreach (range(1, 5) as $weekday) {   // Mo–Fr (GV-Werktage)
                $datum = $start->copy()->addDays(($week - 1) * 7 + ($weekday - 1))->format('Y-m-d');
                foreach ($plan->lines as $linie) {
                    if (isset($belegt[$datum . '|' . $meal . '|' . (int) $linie->id])) {
                        continue;   // Zelle belegt
                    }
                    if ($idx >= self::SPEISEPLAN_MAX_ZELLEN) {
                        $offen++;
                        continue;
                    }
                    $brief = 'Mittagsgericht für die Linie „' . $linie->name . '“' . ($linie->is_vegetarian ? ' (vegetarisch)' : '') . '.';
                    $step = FoodAlchemistCascadeRunStep::create([
                        'team_id' => $team->id,
                        'cascade_run_id' => $run->id,
                        'parent_step_id' => null,
                        'kind' => 'gericht',
                        'label' => Str::limit($linie->name . ' · ' . $datum, 120),
                        'status' => 'running',
                        'sort' => $idx,
                    ]);
                    MaterializeSpeiseplanCellJob::dispatch(
                        $team->id, (int) (\Illuminate\Support\Facades\Auth::id() ?? 0),
                        $planId, $datum, $meal, (int) $linie->id, $brief, (int) $step->id, $session?->id
                    );
                    $idx++;
                }
            }
        }
        if ($offen > 0) {
            $run->update(['params' => ['gedeckelt_zellen_offen' => $offen]]);
        }
        if ($idx === 0) {
            $run->update(['status' => 'done']);   // keine leere Zelle → nichts zu tun (kein Fehler)
        }

        return $run;
    }

    /**
     * Die am Planung-Go gesetzten Richtungs-Regler (Leitplanken) der Session — leer, wenn keine
     * Session/keine Regler. Der Kaskaden-Fan-out erbt sie damit an die erzeugten Gerichte, sodass
     * Niveau/Convenience/Bio/Diät/… nicht nur beim Depth-1-Go greifen, sondern durch die ganze Kaskade.
     *
     * @return array<string,mixed>
     */
    private function sessionGenerationParams(Team $team, ?int $planningSessionId): array
    {
        if ($planningSessionId === null) {
            return [];
        }
        $sess = app(PlanningSessionService::class)->get($team, $planningSessionId);

        return is_array($sess?->generation_params) ? $sess->generation_params : [];
    }

    /**
     * Worker-Logik (aus {@see MaterializeSpeiseplanCellJob}): erdet EINE Speiseplan-Zelle zu einem VK-Gericht
     * ({@see RecipeGeneratorService}, vkModus) und trägt es via {@see SpeiseplanService::addEintrag} in die
     * Zelle (Datum/Mahlzeit/Linie) ein; Trend-Lineage + Rückmeldung an den Step.
     */
    public function materialisiereSpeiseplanZelle(Team $team, int $planId, string $entryDate, string $meal, int $lineId, string $brief, int $stepId, ?int $planningSessionId = null): void
    {
        try {
            // Fan-out erbt die Leitplanken der Session (Regler am Planung-Go); Steuer-Keys gewinnen.
            $params = array_merge($this->sessionGenerationParams($team, $planningSessionId), ['auto_dependencies' => true, 'cascade_step_id' => $stepId]);
            $workflow = app(RecipeDependencyWorkflowService::class);
            $context = $workflow->prepare($team, $stepId, $brief, $params, true);
            $gen = app(RecipeGeneratorService::class)->generiere($team, $brief, $params, null, true, 'plan_go', $context);
            $recipe = $gen['recipe'] ?? null;
            if ($recipe === null) {
                throw new RuntimeException('Generierung lieferte kein Rezept.');
            }
            app(SpeiseplanService::class)->addEintrag($team, $planId, [
                'entry_date' => $entryDate, 'mahlzeit' => $meal, 'line_id' => $lineId, 'sales_recipe_id' => (int) $recipe->id,
            ]);
            if ($planningSessionId !== null) {
                $sess = app(PlanningSessionService::class)->get($team, $planningSessionId);
                if ($sess !== null) {
                    app(PlanningSessionService::class)->verknuepfeArtefakt($sess, 'recipe', (int) $recipe->id);
                }
            }
            $workflow->afterGenerated($team, $stepId, (int) (\Illuminate\Support\Facades\Auth::id() ?? 0), $recipe, $gen['offene'] ?? [], $params);
            $this->markStepDone($stepId, 'recipe', (int) $recipe->id);
        } catch (\Throwable $e) {
            $this->markStepFailed($stepId, $e->getMessage());
        }
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
    public function fanoutConceptInvention(Team $team, int $conceptStepId, int $conceptId, string $mode, ?int $trendDocId = null, ?int $planningSessionId = null): void
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
            // Wissen+Trend fließen in die Divergenz (voller Stack + generischer Trend + Ursprungs-Trend der Planung).
            $div = app(IdeenService::class)->kiDivergenzConcept($team, $conceptId, $leere->count(), null, $trendDocId);
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
            MaterializeConceptIdeaJob::dispatch($team->id, (int) (\Illuminate\Support\Facades\Auth::id() ?? 0), (int) $idee->id, (int) $step->id, $planningSessionId);
        }
    }

    /**
     * Worker-Logik (aus {@see MaterializeConceptIdeaJob}): erdet EINE erfundene Concept-Idee zu einem
     * echten VK-Gericht ({@see RecipeGeneratorService::generiere}, vkModus) und verdrahtet es in den
     * vorgemerkten leeren Slot ({@see ConceptService::fillSlot}); Lineage an die Idee, Rückmeldung an
     * den Kind-Step. Fehler (inkl. KI-Ausfall) → Step failed, Idee markiert — kein „halbes Wrack".
     */
    public function materialisiereConceptGericht(Team $team, int $ideaId, int $stepId, ?int $planningSessionId = null): void
    {
        $idee = FoodAlchemistDishIdea::where('team_id', $team->id)->find($ideaId);
        if ($idee === null) {
            $this->markStepFailed($stepId, 'Idee nicht gefunden.');

            return;
        }
        $slotId = (int) ($idee->source_meta['target_concept_slot_id'] ?? 0);
        $beschreibung = trim(implode(' — ', array_filter([(string) $idee->title, (string) $idee->description]))) ?: (string) $idee->title;

        // Gestuft (Gate pro Ebene): das Gericht schiebt seine Basisrezepte auf bis zu seiner Freigabe.
        $staged = (bool) (FoodAlchemistCascadeRunStep::find($stepId)?->run?->staged ?? false);
        try {
            // Fan-out erbt die Leitplanken der Session (Regler am Planung-Go); Steuer-Keys gewinnen.
            $params = array_merge($this->sessionGenerationParams($team, $planningSessionId), [
                'auto_dependencies' => ! $staged,
                '_defer_children' => $staged,
                'cascade_step_id' => $stepId,
            ]);
            $workflow = app(RecipeDependencyWorkflowService::class);
            $context = $workflow->prepare($team, $stepId, $beschreibung, $params, true);
            $gen = app(RecipeGeneratorService::class)->generiere($team, $beschreibung, $params, null, true, 'plan_go', $context);
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
            // Trend-Herkunft aufs erfundene Rezept durchreichen (source_knowledge_document_id + created_via=plan_go).
            if ($planningSessionId !== null) {
                $sess = app(PlanningSessionService::class)->get($team, $planningSessionId);
                if ($sess !== null) {
                    app(PlanningSessionService::class)->verknuepfeArtefakt($sess, 'recipe', (int) $recipe->id);
                }
            }
            $workflow->afterGenerated($team, $stepId, (int) (\Illuminate\Support\Facades\Auth::id() ?? 0), $recipe, $gen['offene'] ?? [], $params);
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

        // Gestuft (Gate pro Ebene): die Freigabe startet die nächste Stufe UND reichert das Artefakt
        // komplett an (beides als Queue-Job). Bei nicht-gestuften Läufen bleibt es bei der Live-Setzung.
        // Beim async Concept-Fan-out (Job legt die Gericht-Steps erst später an) darf der recompute den
        // Run NICHT auf „done" zurückfallen lassen — starteFolgestufe hält ihn dann selbst auf „running".
        $asyncFolgestufe = $step->run?->staged ? $this->starteFolgestufe($team, $step->fresh()) : false;

        if (! $asyncFolgestufe) {
            $this->recomputeRunStatus((int) $step->cascade_run_id);
        }
    }

    /**
     * Freigabe einer ganzen Stufe: gibt alle noch offenen (`done`) Steps einer `kind` frei — der
     * Stufen-Knopf im Cockpit. Jede Einzel-Freigabe startet die Kinder des Steps (siehe gibStepFrei).
     */
    public function gibStufeFrei(Team $team, int $runId, string $kind): void
    {
        $run = $this->lauf($team, $runId);
        if ($run === null) {
            return;
        }
        foreach ($run->steps->where('status', 'done')->where('kind', $kind) as $s) {
            $this->gibStepFrei($team, (int) $s->id);
        }
    }

    /**
     * Fortsetzung nach der Freigabe eines gestuften Steps: Concept → Gericht-Fan-out ({@see FanoutConceptJob});
     * Rezept/Gericht → aufgeschobene Sub-Rezepte erzeugen + volle Anreicherung (+ optional KI-Fotos, `ki_bilder`).
     * Alles als Queue-Job (kein LLM/Anreicherung inline im Web-Request der Freigabe).
     *
     * @return bool true, wenn eine ASYNCHRONE Folgestufe läuft, die den Run-Status selbst neu bestimmt
     *              (Concept-Fan-out) — dann darf der Aufrufer NICHT recomputen (sonst „done"-Rückfall).
     */
    private function starteFolgestufe(Team $team, FoodAlchemistCascadeRunStep $step): bool
    {
        $userId = (int) (Auth::id() ?? 0);

        if ($step->kind === 'concept') {
            if (is_array($step->deferred['fanout'] ?? null)) {
                // Run auf running halten, bis der Job die Gerichte erzeugt (er recomputet danach selbst).
                $step->run?->update(['status' => 'running']);
                FanoutConceptJob::dispatch($team->id, $userId, (int) $step->id);

                return true;
            }

            return false;
        }

        if ($step->ref_type === 'recipe' && $step->ref_id !== null) {
            $recipe = FoodAlchemistRecipe::where('team_id', $team->id)->find((int) $step->ref_id);
            if ($recipe !== null && is_array($step->deferred['children'] ?? null)) {
                // dispatchChildren legt die Kind-Steps SYNCHRON als running an → recompute sieht sie korrekt.
                app(RecipeDependencyWorkflowService::class)->resumeDeferredChildren($team, $step, $recipe);
            }
            $params = is_array($step->run?->params) ? $step->run->params : [];
            $zielVk = isset($params['ziel_vk_eur']) ? (float) $params['ziel_vk_eur'] : null;
            $kiBilder = (bool) ($params['ki_bilder'] ?? false);
            EnrichRecipeJob::dispatch($team->id, $userId, (int) $step->ref_id, $zielVk, $kiBilder);
        }

        return false;
    }

    /**
     * „Neu generieren" (per-Step-KI im Cockpit): verwirft das aktuelle Draft-Artefakt und stößt die
     * Generierung dieses Steps erneut an (Brief = Step-Label, Params/Session/Staged vom Lauf). Nur
     * rezept|gericht|concept. Der Step geht zurück auf `running`; die Fläche pollt wie beim Go.
     */
    public function regeneriereStep(Team $team, int $stepId): void
    {
        $step = $this->ownedStep($team, $stepId);
        if (! in_array($step->kind, ['rezept', 'gericht', 'concept'], true)) {
            return;
        }
        if ($step->ref_id !== null) {
            if ($step->ref_type === 'recipe') {
                FoodAlchemistRecipe::where('team_id', $team->id)->whereKey($step->ref_id)->delete();
            } elseif ($step->ref_type === 'concept') {
                FoodAlchemistConcept::where('team_id', $team->id)->whereKey($step->ref_id)->delete();
            }
        }
        $step->update(['status' => 'running', 'ref_type' => null, 'ref_id' => null, 'error' => null, 'deferred' => null]);
        $run = $step->run;
        $brief = (string) ($step->label ?? '');
        $params = is_array($run?->params) ? $run->params : [];
        $sessionId = $run?->planning_session_id !== null ? (int) $run->planning_session_id : null;
        $staged = (bool) ($run?->staged ?? false);
        if ($step->kind === 'concept') {
            $this->dispatchConceptStep($team, $step, $brief, $sessionId, (string) ($run?->creative_mode ?? 'voll_kreativ'));
        } else {
            $this->dispatchRezeptStep($team, $step, $brief, $params, $step->kind === 'gericht', false, $sessionId, $staged);
        }
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
