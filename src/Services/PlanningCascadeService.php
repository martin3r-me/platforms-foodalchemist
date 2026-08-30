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
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRecipeDependency;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStepPhoto;
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
 * je Go — Concept im Reuse-Assembler + Erfinden-Fan-out). Die `vollkaskade` (Etappe 5) braucht einen
 * Ausgabe-Owner (`owner_type` foodbook|speisekarte|speiseplan) und wird aus den Ausgabe-Modulen
 * getriggert, nicht frei im Cockpit — ohne Owner wirft {@see starteKaskade} bewusst.
 */
class PlanningCascadeService
{
    /** Async-Result via Cache (Job-Vertrag) — Minuten, bis der Worker den Step abschließt. */
    private const RESULT_TTL_MIN = 15;

    /** Deckel gegen Runaway-Kosten: max. Zellen (= KI-Gericht-Generierungen) je Speiseplan-Voll-Kaskade. */
    private const SPEISEPLAN_MAX_ZELLEN = 30;

    /** Deckel gegen Runaway-Kosten: max. leere Slots (= erfundene KI-Gerichte) je Concept-Fan-out. */
    private const CONCEPT_MAX_SLOTS = 30;

    /**
     * Ab wann ein in-flight Step (`queued`/`running`) als VERWAIST (abgebrochen) gilt — Urteil beim
     * Lesen, kein Spalten-Zustand (Beweis-Philosophie wie {@see \Platform\FoodAlchemist\Models\FoodAlchemistBulkRun::istVerwaist}).
     * Bewusst weit über jeder realen Generierung (Rezept-/Gericht-Generierung dauert Minuten, nicht
     * eine halbe Stunde), damit ein noch lebender, langsamer Job NIE fälschlich für tot erklärt wird.
     * Der 90-s-Watchdog ({@see \Platform\FoodAlchemist\Livewire\Planung\Index::WATCHDOG_SEKUNDEN})
     * WARNT viel früher; das Reapen ({@see reapeVerwaisteSteps}) GREIFT erst, wenn der Job praktisch
     * sicher tot ist.
     */
    public const VERWAIST_NACH_MINUTEN = 30;

    /**
     * Startet einen Kaskaden-Lauf und gibt ihn zurück (Status `running`). Die eigentliche Generierung
     * läuft asynchron im Queue-Job; die Fläche pollt den Run/seine Steps.
     *
     * @param  array{brief?:string, params?:array<string,mixed>, voll_anreichern?:bool, created_via?:string, existing_concept_id?:int, origin_dish_idea_id?:int}  $optionen
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

        // Geplanter Pfad (Etappe 2b, „KI-Kopf"): der Concept-Step referenziert ein SCHON geprüftes
        // Draft-Concept ({@see ConceptGeneratorService::planAusBrief}) statt eines neu zu generierenden.
        // Ownership VOR der Run-Anlage prüfen — ein Fremd-/Fehl-Concept darf keinen Rumpf-Lauf hinterlassen.
        $existingConceptId = $scope === 'concept' ? (int) ($optionen['existing_concept_id'] ?? 0) : 0;
        if ($existingConceptId > 0
            && ! FoodAlchemistConcept::where('team_id', $team->id)->whereKey($existingConceptId)->exists()) {
            throw new RuntimeException("Geprüftes Konzept #{$existingConceptId} nicht gefunden (Team).");
        }

        // Lineage (Etappe 4, Teil 2a): startet der Lauf aus einer Divergenz-Board-Skizze (Skizze →
        // Gericht-Tab → „Go"), trägt er die Ursprungs-Skizze — loser Zeiger für die Status-Rückkopplung
        // auf die Skizzen-Karte. 0/fehlend = kein Skizzen-Ursprung (Bestandsverhalten).
        $originDishIdeaId = (int) ($optionen['origin_dish_idea_id'] ?? 0);

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
            'origin_dish_idea_id' => $originDishIdeaId > 0 ? $originDishIdeaId : null,
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
            if ($existingConceptId > 0) {
                // Kein GenerateConceptJob — Step zeigt direkt auf den geprüften Draft; der Gericht-Fan-out
                // läuft über den bestehenden staged-Pfad (deferred.fanout → FanoutConceptJob).
                $this->referenziereConceptStep($team, $step, $existingConceptId, $creativeMode, $staged, $session?->id);
            } else {
                $this->dispatchConceptStep($team, $step, $brief, $session?->id, $creativeMode, $params);
            }
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
        // Doppel-Anreicherung vermeiden: im gestuften Lauf IST die Freigabe der Anreicherungs-Schritt
        // (starteFolgestufe → EnrichRecipeJob, completeCoverage). Eine Vor-Freigabe-Voll-Anreicherung am
        // noch nicht freigegebenen Draft liefe dieselbe teure Coverage-Kette ein zweites Mal — darum im
        // staged-Modus unterdrücken. Nicht-gestuft (Direkt-Materialisierung) behält das Alt-Verhalten.
        $vorFreigabeAnreichern = $vollAnreichern && ! $staged;
        GenerateRecipeJob::dispatch($runId, $team->id, (int) (\Illuminate\Support\Facades\Auth::id() ?? 0), $brief, $jobParams, $vkModus, $vorFreigabeAnreichern);
    }

    /**
     * Dispatch der Konzept-Generierung für einen Step (Reuse-Assembler; im Erfinden-Modus fächert der Job auf).
     *
     * `$attachOwnerType`/`$attachContainerId` (Spec-42-Vollzug S3a): das erzeugte Konzept dockt an einen
     * Ausgabe-Container (foodbook→Kapitel). Bei Cockpit-Concepts (frei) bleiben beide null. Wichtig, damit
     * ein NEU-Generieren eines Kapitel-Concepts ({@see regeneriereStep}) den Kapitel-Attach nicht verliert.
     */
    private function dispatchConceptStep(Team $team, FoodAlchemistCascadeRunStep $step, string $brief, ?int $planningSessionId, string $creativeMode, array $params = [], ?string $attachOwnerType = null, ?int $attachContainerId = null): void
    {
        $runId = (string) Str::uuid();
        $step->update(['generator_run_id' => $runId]);
        Cache::put(GenerateConceptJob::cacheKey($runId), ['status' => 'pending'], now()->addMinutes(self::RESULT_TTL_MIN));
        // Menü-Leitplanken des Concept-Tabs (menue_*-Keys aus reglerParams) an die Konzept-Erzeugung
        // reichen — sie speisen den Gerüst-Kopf (Preis-Korridor je Person). Nur die menue_*-Teilmenge,
        // damit der Job-Payload schlank und die Absicht klar bleibt.
        $menueAchsen = array_filter($params, fn ($k) => str_starts_with((string) $k, 'menue_'), ARRAY_FILTER_USE_KEY);
        GenerateConceptJob::dispatch(
            $runId, $team->id, (int) (\Illuminate\Support\Facades\Auth::id() ?? 0), $brief,
            null, $planningSessionId, $step->id, $creativeMode, false, false, $attachOwnerType, $attachContainerId, $menueAchsen
        );
    }

    /**
     * Geplanter Pfad (Etappe 2b, „KI-Kopf"): der Concept-Step zeigt auf ein SCHON existierendes, im
     * Conceptor geprüftes Draft-Concept ({@see ConceptGeneratorService::planAusBrief}) — es wird NICHT
     * neu generiert (kein {@see GenerateConceptJob}). Der Step wird direkt `done`; im Erfinden-Modus
     * werden die aufgeschobenen Fan-out-Args wie beim normalen Concept-Job am Step abgelegt
     * ({@see GenerateConceptJob} staged-Zweig, Zeile ~111), sodass die Freigabe den bestehenden
     * Gericht-Fan-out startet ({@see FanoutConceptJob} → {@see fanoutConceptInvention}) — KEIN neuer
     * Fan-out-Code.
     *
     * Ownership ist in {@see starteKaskade} vorab geprüft. Eager (nicht gestuft): der Fan-out läuft
     * sofort, aber im Worker (LLM raus aus dem Web-Request) — denn {@see gibStepFrei} ruft
     * {@see starteFolgestufe} nur bei `staged`.
     */
    private function referenziereConceptStep(
        Team $team,
        FoodAlchemistCascadeRunStep $step,
        int $conceptId,
        string $creativeMode,
        bool $staged,
        ?int $planningSessionId,
    ): void {
        // Nur die Erfinden-Modi fächern auf (Reuse/datenbank füllt keine leeren Slots) — spiegelt
        // GenerateConceptJob. Ohne Erfindung bleibt es beim geprüften Konzept (Slots wie geplant).
        $erfindet = in_array($creativeMode, ['voll_kreativ', 'hybrid'], true);
        if ($erfindet) {
            // Ursprungs-Trend der Planung (falls vorhanden) fließt in die spätere Erfindungs-Divergenz.
            $trendDocId = null;
            if ($planningSessionId !== null) {
                $sess = app(PlanningSessionService::class)->get($team, $planningSessionId);
                $trendDocId = $sess?->source_knowledge_document_id !== null ? (int) $sess->source_knowledge_document_id : null;
            }
            $step->update(['deferred' => ['fanout' => [
                'mode' => $creativeMode,
                'trend_doc_id' => $trendDocId,
                'planning_session_id' => $planningSessionId,
            ]]]);
        }

        // Step abschliessen: er zeigt auf das geprüfte Draft-Concept. recompute → gestuft: `review`
        // (der Mensch gibt die Stufe frei → FanoutConceptJob). Noch ist kein Gericht-Kind da, das
        // Kohäsion-Gate greift korrekt erst nach dem Fan-out.
        $this->markStepDone((int) $step->id, 'concept', $conceptId);

        // Eager (nicht gestuft): die Freigabe ruft starteFolgestufe NICHT (nur staged) — den Fan-out
        // darum hier direkt anstossen, aber als Queue-Job (kein LLM inline im Web-Request des Go).
        if ($erfindet && ! $staged) {
            $step->run?->update(['status' => 'running']);
            FanoutConceptJob::dispatch($team->id, (int) (Auth::id() ?? 0), (int) $step->id);
        }
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
        // P3 foodbook, P4 speisekarte (je 1 Concept/Slot). E2 (Spec 40): offer — je Slot ein Concept, ans
        // Angebot referenziert (Pivot). Der Speiseplan (P5) läuft über einen eigenen Zell-Pfad.
        if (! in_array($ownerType, ['foodbook', 'speisekarte', 'offer'], true) || $ownerId <= 0) {
            throw new RuntimeException('Voll-Kaskade braucht owner_type=foodbook|speisekarte|offer + owner_id.');
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
            // Bewusste Unterscheidung (Etappe 5): Ausgabe-Voll-Kaskaden laufen EAGER (staged=false) —
            // alle Slot-Konzepte werden hier sofort dispatcht und am Ende gemeinsam (Sammel-Review) im
            // Editor geprüft. Der Gegensatz sind die Cockpit-Scopes (rezept|gericht|concept), die
            // gestuft laufen (staged=true, s. starteKaskade Z. 89) — Gate + Freigabe je Ebene. Der Wert
            // ist explizit gesetzt (nicht dem DB-Default überlassen), damit die Absicht sichtbar und
            // gegen ein Ändern des Defaults geschützt ist.
            'staged' => false,
            'source_owner_type' => $ownerType,
            'source_owner_id' => $ownerId,
            'created_via' => (string) ($optionen['created_via'] ?? 'plan_go'),
        ]);

        $slots = $this->vollkaskadeSlots($team, $ownerType, $ownerId, $frame);
        $idx = 0;
        foreach ($slots as [$slot, $containerId]) {
            $this->dispatchSlotConcept($team, $run, $slot, $ownerType, (int) $containerId, $idx, $creativeMode, $session?->id);
            $idx++;
        }
        if ($idx === 0) {
            $run->update(['status' => 'failed']);   // Frame ohne verwertbare Slots
        }

        return $run;
    }

    /**
     * Spec-42-Vollzug S3a — gezielter Teil-Lauf für GENAU EIN Foodbook-Kapitel (statt der ganzen
     * Voll-Kaskade). Nutzt denselben Attach-je-Kapitel-Pfad wie {@see starteVollkaskade} (ein
     * {@see GenerateConceptJob} mit `attachOwnerType='foodbook'` + `attachContainerId=chapter_id`), nur
     * für das eine gekoppelte Slot↔Kapitel-Paar. Das „Kapitel-Go" der Leitstelle ersetzt damit den alten
     * kaskaden-fremden `FoodbookService::kapitelFreigeben`-Bypass — jede Erzeugung läuft über den Motor.
     *
     * Jeder Aufruf = ein NEUER Run mit einem Step (kein Anhängen an fremde Runs → keine Race). Nicht
     * idempotent — Doppel-Klick-Schutz macht die UI (Button-Disable am jüngsten „läuft"-Step des Kapitels).
     */
    public function starteKapitelKaskade(Team $team, ?FoodAlchemistPlanningSession $session, string $creativeMode, int $foodbookId, int $chapterId, array $optionen = []): FoodAlchemistCascadeRun
    {
        if (! in_array($creativeMode, FoodAlchemistPlanningSession::CREATIVE_MODES, true)) {
            $creativeMode = 'voll_kreativ';
        }
        $frame = app(PlanningFrameService::class)->find('foodbook', $foodbookId);
        if ($frame === null || $frame->slots()->count() === 0) {
            throw new RuntimeException('Foodbook hat noch kein Planungs-Gerüst — erst Kickoff/Struktur anlegen.');
        }
        // Genau das gekoppelte Slot↔Kapitel-Paar suchen (vollkaskadeSlots stellt strukturAusGeruest-Idempotenz sicher).
        $treffer = null;
        foreach ($this->vollkaskadeSlots($team, 'foodbook', $foodbookId, $frame) as [$slot, $containerId]) {
            if ((int) $containerId === $chapterId) {
                $treffer = [$slot, (int) $containerId];
                break;
            }
        }
        if ($treffer === null) {
            throw new RuntimeException('Kapitel hat keinen gekoppelten Gerüst-Slot — nur gerüst-basierte Kapitel sind erzeugbar.');
        }

        $run = FoodAlchemistCascadeRun::create([
            'team_id' => $team->id,
            'planning_session_id' => $session?->id,
            'scope' => 'vollkaskade',   // gleiche Scope-Semantik wie die Voll-Kaskade (nur 1 Slot)
            'creative_mode' => $creativeMode,
            'brief' => 'Kapitel-Kaskade foodbook #' . $foodbookId . ' — Kapitel #' . $chapterId,
            'status' => 'running',
            'staged' => false,
            'source_owner_type' => 'foodbook',
            'source_owner_id' => $foodbookId,
            'created_via' => (string) ($optionen['created_via'] ?? 'leitstelle_kapitel_go'),
        ]);
        [$slot, $containerId] = $treffer;
        $this->dispatchSlotConcept($team, $run, $slot, 'foodbook', $containerId, 0, $creativeMode, $session?->id);

        return $run;
    }

    /**
     * Ein Slot → ein Concept-Step + {@see GenerateConceptJob} (Attach an den Owner-Container). Der EINE
     * Ort, an dem `chapter_id`/`slot_id` am Step gesetzt werden — genutzt von {@see starteVollkaskade}
     * (Schleife) UND {@see starteKapitelKaskade} (einmal). Für foodbook trägt der Brief die editierten
     * Kapitel-Ziele ({@see kapitelBrief}, Kapitel schlägt Slot), sonst der reine {@see slotBrief}.
     */
    private function dispatchSlotConcept(Team $team, FoodAlchemistCascadeRun $run, $slot, string $ownerType, int $containerId, int $idx, string $creativeMode, ?int $sessionId): FoodAlchemistCascadeRunStep
    {
        $chapterId = $ownerType === 'foodbook' ? $containerId : null;
        $step = FoodAlchemistCascadeRunStep::create([
            'team_id' => $team->id,
            'cascade_run_id' => $run->id,
            'parent_step_id' => null,
            'kind' => 'concept',
            'label' => Str::limit((string) ($slot->label ?: 'Konzept'), 120),
            'status' => 'running',
            'sort' => $idx,
            'chapter_id' => $chapterId,
            'slot_id' => (int) $slot->id,
        ]);
        $brief = $chapterId !== null
            ? $this->kapitelBrief($chapterId, $slot)
            : $this->slotBrief($ownerType, (int) $run->source_owner_id, $slot);
        $runId = (string) Str::uuid();
        $step->update(['generator_run_id' => $runId]);
        Cache::put(GenerateConceptJob::cacheKey($runId), ['status' => 'pending'], now()->addMinutes(self::RESULT_TTL_MIN));
        GenerateConceptJob::dispatch(
            $runId, $team->id, (int) (\Illuminate\Support\Facades\Auth::id() ?? 0),
            $brief, (string) ($slot->label ?: null),
            $sessionId, $step->id, $creativeMode, false, false, $ownerType, $containerId
        );

        return $step;
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
        if ($ownerType === 'offer') {
            // E2 (Spec 40): der „Container" IST das Angebot selbst — jedes erzeugte Konzept wird ans Angebot
            // referenziert (Pivot foodalchemist_offer_concept, {@see AngebotService::referenziereConcept}); es gibt
            // keinen Zwischen-Container wie Kapitel/Rubrik. Darum ist die containerId überall die Angebots-ID.
            $frame->load('slots');
            foreach ($frame->slots as $slot) {
                $out[] = [$slot, $ownerId];
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

    /**
     * Wie {@see slotBrief}, aber die editierten M3-Kapitel-Ziele schlagen den Slot (Spec-42-Vollzug S3a):
     * Zielanzahl + Preis-Anker kommen aus dem Kapitel, wenn dort gesetzt; das Kapitel-Niveau kommt additiv
     * dazu. So wirkt die Kapitel-Steuerung der Leitstelle tatsächlich auf die frische Generierung.
     */
    private function kapitelBrief(int $chapterId, $slot): string
    {
        $kap = \Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel::find($chapterId);
        $teile = ['[' . ($slot->label ?: ($kap?->title ?: 'Gang')) . ']'];
        $targetCount = ($kap && $kap->target_count !== null) ? (int) $kap->target_count : (int) $slot->target_count;
        if ($targetCount > 0) {
            $teile[] = 'Zielanzahl Gerichte: ' . $targetCount;
        }
        $priceAnchor = ($kap && $kap->price_anchor !== null) ? $kap->price_anchor : $slot->price_anchor;
        if ($priceAnchor !== null) {
            $teile[] = 'Preis-Anker p.P.: ' . $priceAnchor . ' €';
        }
        if ($kap && $kap->niveau !== null && trim((string) $kap->niveau) !== '') {
            $teile[] = 'Niveau: ' . $kap->niveau;
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
            // Wie Foodbook/Speisekarte: EAGER (staged=false) — je leerer Zelle sofort ein Gericht-Job,
            // Sammel-Review im Editor. Explizit gesetzt (nicht DB-Default), s. starteVollkaskade.
            'staged' => false,
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

        // Spec-42-Vollzug S4: der Session-Brief (Anlass/Saison/Richtung) steuert jede Zell-Generierung mit
        // („Speiseplan aus Brief"). Leer = unverändertes Alt-Verhalten (nur Linien-Brief).
        $planKontext = trim((string) ($session?->brief ?? ''));
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
                    $brief = ($planKontext !== '' ? 'Rahmen: ' . $planKontext . ' — ' : '')
                        . 'Mittagsgericht für die Linie „' . $linie->name . '“' . ($linie->is_vegetarian ? ' (vegetarisch)' : '') . '.';
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
     * **Leitplanken-Trennung (Roadmap Et.2a):** dieser Helfer speist ausschliesslich die REZEPT-
     * Erzeugung ({@see materialisiereConceptGericht}, {@see materialisiereSpeiseplanZelle}). Nur die
     * REZEPT-Leitplanken (Niveau/Convenience/Frische/Bio/Diät/Aroma/…) propagieren an Gerichte/
     * Basisrezepte. Die MENÜ-Leitplanken (`menue_*`: Anzahl Gänge · Preis-Korridor je Person · Diät-
     * Quoten · Portfolio-Balance) steuern die ZUSAMMENSTELLUNG des Menüs (Concept-Ebene, gelesen beim
     * Concept-Dispatch) und werden hier bewusst herausgefiltert — für ein einzelnes Gericht/Basisrezept
     * sind sie bedeutungslos und würden, in den Rezept-Prompt serialisiert, die Generierung verfälschen.
     *
     * @return array<string,mixed>
     */
    private function sessionGenerationParams(Team $team, ?int $planningSessionId): array
    {
        if ($planningSessionId === null) {
            return [];
        }
        $sess = app(PlanningSessionService::class)->get($team, $planningSessionId);
        $params = is_array($sess?->generation_params) ? $sess->generation_params : [];

        // Menü-Leitplanken bleiben auf der Concept-Ebene — nicht in die Rezept-Generierung durchreichen.
        return array_filter($params, fn ($k) => ! str_starts_with((string) $k, 'menue_'), ARRAY_FILTER_USE_KEY);
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
     * Gedeckelt ({@see CONCEPT_MAX_SLOTS}) gegen Runaway-Kosten bei großem Menü-Brief; überzählige leere
     * Slots werden übersprungen und ihre Zahl im Run (`params.gedeckelt_slots_offen`) vermerkt — kein
     * stiller Deckel (analog {@see SPEISEPLAN_MAX_ZELLEN}).
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

        // Deckel gegen Runaway-/Kosten-Risiko bei großem Menü-Brief (analog SPEISEPLAN_MAX_ZELLEN): wir fragen
        // die KI gar nicht erst nach mehr als N Ideen und legen höchstens N Kind-Steps/Jobs an. Die Zahl der
        // übersprungenen Slots steht im Run (`params.gedeckelt_slots_offen`) — kein stiller Deckel.
        if ($leere->count() > self::CONCEPT_MAX_SLOTS) {
            $offen = $leere->count() - self::CONCEPT_MAX_SLOTS;
            $leere = $leere->take(self::CONCEPT_MAX_SLOTS);
            $run = FoodAlchemistCascadeRun::find($runId);
            if ($run !== null) {
                $run->update(['params' => array_merge(is_array($run->params) ? $run->params : [], ['gedeckelt_slots_offen' => $offen])]);
            }
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
            // Et.6 (Roadmap Z.205): Zielpreis-Korridor Concept → Gericht — je erfundenem Gericht einen
            // Ziel-VK aus dem Concept-Frame ableiten (aus dem Fan-out des Concepts, nicht aus dem Rezept-Regler).
            // Ein bereits gesetzter ziel_vk_eur (explizite Rezept-Leitplanke) gewinnt und wird nicht überschrieben.
            if (! isset($params['ziel_vk_eur'])) {
                $zielVk = $this->conceptGerichtZielVk($team, $slotId);
                if ($zielVk !== null) {
                    $params['ziel_vk_eur'] = $zielVk;
                }
            }
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

    /**
     * Et.6 (Roadmap Z.205): **Zielpreis-Korridor Concept → Gericht (aus Frame).** Leitet für ein
     * erfundenes Concept-Gericht einen Ziel-VK (netto je Portion) aus dem Concept-Frame ab, damit
     * das Wirtschaftlichkeits-Glied der Anreicherung ({@see RecipeOneShotService::wirtschaftlichkeitsGlied}
     * via {@see EnrichRecipeJob}) das Gericht gegen ein echtes Ziel misst statt gegen null.
     *
     * Quelle in Reihenfolge (keine Erfindung, „aus Frame"):
     *   1. **Preis-Anker des passenden Frame-Slots** (per Gericht, via Slot-Rolle = Frame-Slot-Label
     *      gematcht) — die verlustfreie per-Gericht-Angabe, die der Frame direkt trägt;
     *   2. sonst der **Frame-Kopf-Zielpreis je Person** (`target_price_pp`) gleichmäßig auf die Positionen
     *      verteilt (`target_price_pp / Σ target_count`). Ein Menü-Zielpreis je Person wird nur durch die
     *      Zahl der Gänge zu einem per-Gericht-Wert — die Gleichverteilung ist eine bewusste, dokumentierte
     *      Allokations-Annahme (kein erfundener Datenwert), greift nur, wenn kein Slot-Anker vorliegt.
     * Kein Ziel-Slot / kein Frame / kein Preis / Wert ≤ 0 → null (kein `ziel_vk_eur`, Bestandsverhalten).
     */
    private function conceptGerichtZielVk(Team $team, int $slotId): ?float
    {
        if ($slotId <= 0) {
            return null;
        }
        $slot = FoodAlchemistConceptSlot::find($slotId);
        if ($slot === null) {
            return null;
        }
        $frame = app(PlanningFrameService::class)->find('concept', (int) $slot->concept_id);
        if ($frame === null) {
            return null;
        }
        $frame->loadMissing('slots');

        // 1) Preis-Anker des Frame-Slots mit gleicher Rolle (Label) — per Gericht, verlustfrei.
        $rolle = trim((string) ($slot->role ?? ''));
        if ($rolle !== '') {
            $treffer = $frame->slots->first(
                fn ($fs) => trim((string) ($fs->label ?? '')) === $rolle && $fs->price_anchor !== null
            );
            if ($treffer !== null) {
                $anker = round((float) $treffer->price_anchor, 2);
                if ($anker > 0) {
                    return $anker;
                }
            }
        }

        // 2) Kopf-Zielpreis je Person, gleichmäßig auf die Positionen (Σ target_count) verteilt.
        if ($frame->target_price_pp !== null) {
            $positionen = (int) $frame->slots->sum(fn ($fs) => max(1, (int) ($fs->target_count ?? 1)));
            if ($positionen > 0) {
                $jeGericht = round((float) $frame->target_price_pp / $positionen, 2);
                if ($jeGericht > 0) {
                    return $jeGericht;
                }
            }
        }

        return null;
    }

    // ── Rückkanal aus dem Job (läuft im Queue-Worker) ──────────────────────

    /** Step erfolgreich: erzeugtes Artefakt festhalten, dann Run-Status neu bestimmen. */
    public function markStepDone(int $stepId, string $refType, int $refId): void
    {
        $step = FoodAlchemistCascadeRunStep::find($stepId);
        if ($step === null) {
            return;
        }
        // L5: das Step-Label auf den ECHTEN Artefakt-Namen nachziehen. Bei der Anlage war es der auf
        // 120 Zeichen geschnittene Brief — das Cockpit zeigte also den Briefing-Text statt des Rezept-/
        // Concept-Namens. Der Brief bleibt separat im Run-Kopf sichtbar. Fail-soft (Name-Auflösung optional).
        $artefaktName = $this->artefaktName($step->team_id ? (int) $step->team_id : null, $refType, $refId);
        $updates = ['status' => 'done', 'ref_type' => $refType, 'ref_id' => $refId, 'error' => null];
        if ($artefaktName !== null && $artefaktName !== '') {
            $updates['label'] = Str::limit($artefaktName, 120, '');
        }
        $step->update($updates);
        $this->recomputeRunStatus((int) $step->cascade_run_id);
        $this->scoreConceptCohesionIfComplete($step);
    }

    /** L5: Anzeigename des erzeugten Artefakts (Recipe/Concept) — für das Step-Label. Fail-soft → null. */
    private function artefaktName(?int $teamId, string $refType, int $refId): ?string
    {
        if ($teamId === null || $refId <= 0) {
            return null;
        }
        try {
            return match ($refType) {
                'recipe' => FoodAlchemistRecipe::where('team_id', $teamId)->whereKey($refId)->value('name'),
                'concept' => FoodAlchemistConcept::where('team_id', $teamId)->whereKey($refId)->value('name'),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
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
        $this->scoreConceptCohesionIfComplete($step);
    }

    /**
     * #124: Ein Fan-out-Abbruch ist NICHT „Concept kaputt". Das Concept-Rezept ist längst angelegt
     * und freigegeben (der Fan-out läuft NACH der Freigabe) — nur die automatische Gericht-Erfindung
     * crashte. Darum den Step-Status (`freigegeben`) LASSEN und den Fehler separat in
     * `deferred.fanout_error` festhalten → Cockpit zeigt „Auto-Gericht-Erfindung fehlgeschlagen",
     * nicht „Concept fehlgeschlagen". Ist der Step (wider Erwarten) noch nicht freigegeben, war das
     * Concept nie live → dann echter Step-Fehler ({@see markStepFailed}).
     */
    public function markFanoutFailed(int $stepId, string $error): void
    {
        $step = FoodAlchemistCascadeRunStep::find($stepId);
        if ($step === null) {
            return;
        }
        if ($step->status !== 'freigegeben') {
            $this->markStepFailed($stepId, $error);   // Concept war nie live → echter Fehler

            return;
        }
        $deferred = is_array($step->deferred) ? $step->deferred : [];
        unset($deferred['fanout']);                   // Fan-out-Args sind verbraucht/tot
        $deferred['fanout_error'] = Str::limit($error, 500, '');
        $step->update(['deferred' => $deferred]);
        $this->recomputeRunStatus((int) $step->cascade_run_id);
        $this->scoreConceptCohesionIfComplete($step);
    }

    /**
     * E-P0 (Spec 40): Der Attach des erzeugten Konzepts ans Ausgabe-Kapitel/die Rubrik ist fehlgeschlagen —
     * aber das Konzept IST erzeugt (der Concept-Step wird gleich regulär `done`, {@see markStepDone} läuft
     * NACH diesem Aufruf und lässt `deferred` unangetastet). Kein „Konzept kaputt": den Fehler + die Ziel-Info
     * separat in `deferred` festhalten (analog {@see markFanoutFailed}, #124), damit das Cockpit ein sichtbares,
     * behebbares Amber-Signal zeigt und die Recovery-Aktion {@see haengeKonzeptNach} den Attach nachholen kann.
     * Der Step-Status bleibt bewusst unberührt (das Konzept ist gültig). Wirft nie (Rückkanal aus dem Job).
     */
    public function markAttachFailed(int $stepId, string $ownerType, int $containerId, int $conceptId, string $error): void
    {
        $step = FoodAlchemistCascadeRunStep::find($stepId);
        if ($step === null) {
            return;
        }
        $deferred = is_array($step->deferred) ? $step->deferred : [];
        $deferred['attach_error'] = Str::limit($error, 500, '');
        $deferred['pending_attach'] = [
            'owner_type' => $ownerType,
            'container_id' => $containerId,
            'concept_id' => $conceptId,
        ];
        $step->update(['deferred' => $deferred]);
    }

    /**
     * E-P0-Recovery (Spec 40): einen zuvor fehlgeschlagenen Attach nachholen — das (existierende) Konzept
     * ans in `deferred.pending_attach` gemerkte Ausgabe-Kapitel/die Rubrik hängen (reuse {@see FoodbookService::addBlock}
     * bzw. {@see SpeisekarteService::addPosition}). Gelingt es, wird das Signal (`attach_error`/`pending_attach`)
     * gelöscht. Scheitert es erneut, propagiert der Fehler an den Aufrufer (Livewire zeigt ihn als Toast) und das
     * Signal bleibt stehen. Team-scoped ({@see ownedStep}).
     */
    public function haengeKonzeptNach(Team $team, int $stepId): void
    {
        $step = $this->ownedStep($team, $stepId);
        $deferred = is_array($step->deferred) ? $step->deferred : [];
        $pending = is_array($deferred['pending_attach'] ?? null) ? $deferred['pending_attach'] : null;
        if ($pending === null) {
            return;   // nichts nachzuholen
        }
        $ownerType = (string) ($pending['owner_type'] ?? '');
        $containerId = (int) ($pending['container_id'] ?? 0);
        $conceptId = (int) ($pending['concept_id'] ?? 0);
        if ($containerId <= 0 || $conceptId <= 0) {
            return;
        }
        if ($ownerType === 'foodbook') {
            app(FoodbookService::class)->addBlock($team, $containerId, ['type' => 'concept_ref', 'concept_id' => $conceptId]);
        } elseif ($ownerType === 'speisekarte') {
            app(SpeisekarteService::class)->addPosition($team, $containerId, ['type' => 'menue_ref', 'concept_id' => $conceptId]);
        } elseif ($ownerType === 'offer') {
            app(AngebotService::class)->referenziereConcept($team, $containerId, $conceptId);   // E2: containerId = Angebots-ID
        } else {
            return;
        }
        unset($deferred['attach_error'], $deferred['pending_attach']);
        $step->update(['deferred' => $deferred]);
    }

    /**
     * E4 (Spec 40): Sourcing-Lücken eines erzeugten Rezept-Steps ins Signale-Cockpit melden — die im Step
     * verwendeten GPs OHNE beschaffbaren Lead-LA (verfuegbarkeit-Bucket `luecke`) je als Sortiments-Lücke
     * ({@see PairingInspirationService::meldeLuecke}, idempotent je GP-Name). Nordstern „Lücke ist Signal,
     * kein Fehler". Team-scoped ({@see ownedStep}). Gibt die gemeldeten GP-Namen zurück.
     *
     * @return list<string>
     */
    public function meldeSourcingLuecken(Team $team, int $stepId): array
    {
        $step = $this->ownedStep($team, $stepId);
        if ($step->ref_type !== 'recipe' || $step->ref_id === null) {
            return [];
        }
        $gpIds = FoodAlchemistRecipeIngredient::where('recipe_id', (int) $step->ref_id)
            ->whereNotNull('gp_id')->pluck('gp_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        if ($gpIds === []) {
            return [];
        }
        $verf = app(FavoriteGpService::class)->verfuegbarkeit($team, $gpIds);
        $luecken = array_keys(array_filter($verf, fn ($v) => ($v['bucket'] ?? null) === 'luecke'));
        if ($luecken === []) {
            return [];
        }
        $namen = \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->whereIn('id', $luecken)->pluck('name', 'id');
        $inspiration = app(PairingInspirationService::class);
        $gemeldet = [];
        foreach ($namen as $gpId => $name) {
            if (trim((string) $name) === '') {
                continue;
            }
            $inspiration->meldeLuecke($team, (string) $name, [
                'gp_id' => (int) $gpId,
                'quelle_step_id' => (int) $step->id,
                'quelle' => 'kaskade_step',
            ]);
            $gemeldet[] = (string) $name;
        }

        return $gemeldet;
    }

    /**
     * E4 (Spec 40): Favoriten-Kandidaten aus einem erzeugten Rezept-Step — die verwendeten GPs, die NOCH
     * NICHT als Favorit gepinnt sind. Reiner Vorschlag: das Pinnen bleibt mensch-gated (eigene Aktion,
     * {@see FavoriteGpService::pin}). Team-scoped.
     *
     * @return list<array{id:int, name:string}>
     */
    public function favoritKandidatenFuerStep(Team $team, int $stepId): array
    {
        $step = $this->ownedStep($team, $stepId);
        if ($step->ref_type !== 'recipe' || $step->ref_id === null) {
            return [];
        }
        $gpIds = FoodAlchemistRecipeIngredient::where('recipe_id', (int) $step->ref_id)
            ->whereNotNull('gp_id')->pluck('gp_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        if ($gpIds === []) {
            return [];
        }

        return \Platform\FoodAlchemist\Models\FoodAlchemistGp::visibleToTeam($team)->whereIn('id', $gpIds)
            ->where('is_favorite', false)
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn ($g) => ['id' => (int) $g->id, 'name' => (string) $g->name])->all();
    }

    /**
     * Idempotenz/Resume (Etappe 8) — eine abgebrochene Kaskade sauber fortsetzbar machen.
     *
     * Stirbt ein Generator-Job hart (OOM/Timeout/Worker-Kill), ohne seinen `failed()`-Haken zu feuern,
     * bleibt sein Step ewig `queued`/`running`; der Run verharrt in `running` ({@see recomputeRunStatus}),
     * der Cockpit-Spinner löst nie auf, und WEDER {@see verwirfStep}/{@see verwirfRun} (setzen `done`/
     * `failed` voraus) NOCH {@see gibRunFrei} greifen — der Lauf ist eine Sackgasse.
     *
     * Dieser Reaper markiert die VERWAISTEN in-flight Steps (älter als {@see VERWAIST_NACH_MINUTEN})
     * als `failed` — über {@see markStepFailed}, damit der Run neu bewertet wird (raus aus dem ewigen
     * `running`) und bei Concept-Kindern das Kohärenz-Gate scoren kann. Danach ist der Lauf wieder
     * handlungsfähig: der Mensch generiert die verwaisten Steps einzeln neu ({@see regeneriereStep})
     * oder verwirft sie.
     *
     * Konservativ (Beweis-Philosophie): NICHT-verwaiste (junge) in-flight Steps bleiben unangetastet —
     * ein langsamer, noch lebender Job wird nie abgewürgt. Team-scoped über {@see lauf}.
     *
     * @return int Zahl der reapten Steps (0 = nichts verwaist)
     */
    public function reapeVerwaisteSteps(Team $team, int $runId): int
    {
        $run = $this->lauf($team, $runId);
        // D1-Slice 3: sichtbarer, nicht besessener Lauf → sauberer No-op. Hier ist das KEINE Kosmetik,
        // sondern ein echter Cross-Tenant-Write-Riegel: markStepFailed(stepId) trägt (anders als
        // regeneriereStep) KEINEN ownedStep-Guard → ohne diese Zeile könnte ein Kind-Team die Steps
        // des vererbten Eltern-Laufs auf `failed` setzen. Deckungsgleich mit `lauf()===null`.
        if ($run === null || ! $run->isOwnedBy($team)) {
            return 0;
        }
        $grenze = now()->subMinutes(self::VERWAIST_NACH_MINUTEN);
        $verwaist = $run->steps->filter(fn ($s) => in_array($s->status, ['queued', 'running'], true)
            && $s->updated_at !== null
            && $s->updated_at->lt($grenze));
        foreach ($verwaist as $s) {
            $this->markStepFailed((int) $s->id, 'Abgebrochen — keine Rückmeldung vom Worker (verwaist). Neu generieren oder verwerfen.');
        }

        return $verwaist->count();
    }

    /**
     * Gebündeltes Resume (Idempotenz/Resume, Etappe 8 Teil 2): alle GESCHEITERTEN (`failed`)
     * generierbaren Steps eines Laufs auf einmal RE-DISPATCHEN — statt sie einzeln über
     * {@see regeneriereStep} neu zu generieren. Reust {@see regeneriereStep} je Step (verwirft das
     * Teil-Wrack, setzt den Step auf `running` und dispatcht den passenden Generator-Job neu).
     *
     * IDEMPOTENT gegen Doppel-Jobs: es werden AUSSCHLIESSLICH `failed`-Steps angefasst — in-flight
     * Steps (`queued`/`running`) bleiben unangetastet, ebenso `done`/`geplant`/`skipped`/`verworfen`.
     * Da {@see regeneriereStep} den Step SOFORT auf `running` flippt, sieht ein zweiter Aufruf (Doppel-
     * Klick, während der Resume-Lauf noch arbeitet) den Step nicht mehr als `failed` → kein Doppel-Job.
     * {@see lauf} lädt die Steps je Aufruf frisch aus der DB, sodass die Idempotenz auch über getrennte
     * Requests hält.
     *
     * Ergänzt den {@see reapeVerwaisteSteps}-Reaper (Teil 1): der macht harte Hänger erst zu `failed`,
     * dieser Resume greift dann alle `failed`-Steps (verwaist ODER regulär gescheitert) gebündelt.
     * Nur die generierbaren Kinds (rezept|gericht|concept); GP-/Referenz-Steps tragen keinen Generator.
     *
     * @return int Zahl der re-dispatchten Steps (0 = nichts Gescheitertes zum Fortsetzen)
     */
    public function setzeLaufFort(Team $team, int $runId): int
    {
        $run = $this->lauf($team, $runId);
        // D1-Slice 3: sichtbarer, nicht besessener Lauf → sauberer No-op statt lautem Wurf im ersten
        // regeneriereStep (dessen ownedStep fängt es zwar, aber laut). Siehe reapeVerwaisteSteps/gibStufeFrei.
        if ($run === null || ! $run->isOwnedBy($team)) {
            return 0;
        }
        $gescheitert = $run->steps->filter(fn ($s) => $s->status === 'failed'
            && in_array($s->kind, ['rezept', 'gericht', 'concept'], true));
        foreach ($gescheitert as $s) {
            $this->regeneriereStep($team, (int) $s->id);
        }

        return $gescheitert->count();
    }

    // ── Auto-Trigger: Menü-Folge-Kohärenz-Gate nach der Fan-out-Erfindung ──

    /**
     * Feuert das Menü-Folge-Kohärenz-Gate automatisch, sobald die per Fan-out ERFUNDENE Menüfolge
     * eines Concept-Steps vollständig geerdet ist — statt auf den manuellen „Kohäsion prüfen"-Klick
     * im Conceptor zu warten. Die erfundene Folge wird erst NACH dem Grounding scorebar (Skizzen /
     * laufende Steps tragen noch keine Anker), deshalb hier am Fan-out-Abschluss und nicht schon bei
     * der Erfindung ({@see fanoutConceptInvention}).
     *
     * Der Abschluss-Haken sitzt am Job-Rückkanal ({@see markStepDone}/{@see markStepFailed}): jeder
     * {@see \Platform\FoodAlchemist\Jobs\MaterializeConceptIdeaJob} meldet EINEN Gericht-Step; der
     * Handler bestimmt den zugehörigen Concept-Step (der meldende Step selbst = eager-Pfad, wo die
     * Kinder inline liefen; oder dessen Concept-Eltern = async-Pfad, wo der LETZTE Kind-Job die
     * Erfindung abschließt) und scored erst, wenn KEIN erfundenes Gericht mehr offen ist.
     *
     * Fail-soft: eine nicht-scorebare Folge (Provider-los, ungemappt, zu wenig Gerichte) persistiert
     * `null` und darf den Rückkanal nie kippen.
     */
    private function scoreConceptCohesionIfComplete(?FoodAlchemistCascadeRunStep $step): void
    {
        if ($step === null) {
            return;
        }
        // Den zugehörigen Concept-Step bestimmen: DIESER Step ist es selbst (eager: der Concept meldet
        // sich fertig, seine erfundenen Gerichte liefen schon inline), oder ein erfundener Gericht-Step
        // meldet sich (async: der letzte Kind-Job schließt die Fan-out-Erfindung ab).
        if ($step->kind === 'concept') {
            $conceptStep = $step;
        } elseif ($step->kind === 'gericht' && $step->parent_step_id !== null) {
            $conceptStep = FoodAlchemistCascadeRunStep::find($step->parent_step_id);
        } else {
            return;   // Rezept/GP-Steps oder freie Gericht-Läufe (kein Concept-Eltern) → kein Menü-Gate
        }
        if ($conceptStep === null || $conceptStep->kind !== 'concept' || $conceptStep->ref_id === null) {
            return;
        }

        // Scorebar erst, wenn ALLE erfundenen Gericht-Geschwister durch sind (kein Job/Platzhalter mehr
        // offen) UND überhaupt ein erfundenes Gericht existiert (ohne Fan-out gibt es keine Folge).
        $gerichte = FoodAlchemistCascadeRunStep::where('parent_step_id', $conceptStep->id)
            ->where('kind', 'gericht')->get(['status']);
        if ($gerichte->isEmpty()
            || $gerichte->whereIn('status', ['geplant', 'queued', 'running'])->isNotEmpty()) {
            return;
        }

        $team = Team::find($conceptStep->team_id);
        if ($team === null) {
            return;
        }
        try {
            $this->persistConceptCohesion((int) $conceptStep->cascade_run_id, $team, (int) $conceptStep->ref_id);
        } catch (\Throwable) {
            // fail-soft: eine nicht-scorebare Folge darf den Job-Rückkanal nie kippen
        }
    }

    /**
     * Berechnet die Aroma-Kohäsion der Gerichte eines Concepts ({@see PairingService::menuCohesion} →
     * {@see PairingService::menuKohaesionWarnung}) und persistiert die abgestufte Warnung am Run
     * (`cohesion_warning`). Gleiche Auffaltung der Gerichte wie {@see \Platform\FoodAlchemist\Livewire\Concepter\Editor::kohaesionPruefen}
     * (Slot-Gericht + Paket-Gerichte, dubletten-frei). `null`, wenn nichts zu beurteilen ist.
     */
    private function persistConceptCohesion(int $runId, Team $team, int $conceptId): void
    {
        $concept = app(ConceptService::class)->detail($team, $conceptId);
        if ($concept === null) {
            return;
        }
        $dishes = [];
        foreach ($concept->slots as $slot) {
            if ($slot->dish) {
                $dishes[$slot->dish->id] = $slot->dish;
            } elseif ($slot->package) {
                foreach ($slot->package->dishes as $pg) {
                    if ($pg->dish) {
                        $dishes[$pg->dish->id] = $pg->dish;
                    }
                }
            }
        }
        $pairing = app(PairingService::class);
        $kohaesion = count($dishes) >= 2
            ? $pairing->menuCohesion(array_values($dishes))
            : ['zu_wenig' => true];
        $warnung = $pairing->menuKohaesionWarnung($kohaesion);

        FoodAlchemistCascadeRun::find($runId)?->update(['cohesion_warning' => $warnung]);
    }

    /**
     * Run-Status aus den Steps ableiten:
     * - ein Step läuft (queued|running)                        → `running`
     * - ein Step ist erzeugt, aber unentschieden (done)        → `review` (Gate 2 offen)
     * - ein Step ist geplant (Sub-Rezept wartet auf Freigabe)  → `review` (der Mensch ist am Zug)
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
        // `geplant` = benanntes, noch nicht erzeugtes Sub-Rezept: es wartet auf einen MENSCHEN
        // (Freigabe der Stufe darüber), nicht auf den Worker — also review, nicht running/failed.
        if ($steps->whereIn('status', ['done', 'geplant'])->count() > 0) {
            $run->update(['status' => 'review']);

            return;
        }
        $positiv = $steps->whereIn('status', ['freigegeben', 'skipped'])->count();
        $hatFehler = $steps->where('status', 'failed')->count() > 0;
        // L4: „done" darf nicht lügen. Ist ein Kind-Step gescheitert (und nichts mehr in-flight/geplant),
        // bleibt der Run in `review` statt `done` — der Mensch sieht den Fehler im Cockpit und kann den
        // Step neu erzeugen. Nur ein sauberer Abschluss ohne failed-Step meldet `done`.
        if ($positiv > 0) {
            $run->update(['status' => $hatFehler ? 'review' : 'done']);

            return;
        }
        $run->update(['status' => 'failed']);
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
        // D1-Slice 3: ein SICHTBARER, aber nicht besessener Lauf (childA sieht den vererbten
        // Root-Lauf) ist hier ein sauberer No-op — nicht ein lauter Wurf im ersten `gibStepFrei`.
        // Der per-Step `ownedStep`-Guard fängt es zwar korrekt (kein Write), aber laut statt leise;
        // ein Fremd-Lauf hat für dieses Team schlicht nichts freizugeben. Deckungsgleich mit `lauf()===null`.
        if ($run === null || ! $run->isOwnedBy($team)) {
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
            // Anreicherungs-Tiefe (Step-by-Step/Sensorik/…): Default an = Bestandsverhalten; per Leitplanke
            // (generation_params.complete_coverage=false) auf „leichte" Anreicherung stellbar. GP-Mint bleibt
            // im Job unabhängig davon an (EK).
            $completeCoverage = array_key_exists('complete_coverage', $params) ? (bool) $params['complete_coverage'] : true;
            // Anreicherung SYNCHRON als `queued` markieren → die Planung pollt sichtbar durch (der Run
            // ist bei einem flachen Gericht sofort „done", der Job läuft aber async danach).
            $this->markEnrichQueued($step);
            EnrichRecipeJob::dispatch($team->id, $userId, (int) $step->ref_id, $zielVk, $kiBilder, (int) $step->id, false, false, $completeCoverage);
        }

        return false;
    }

    /** Anreicherungs-Status eines Rezept-/Gericht-Steps synchron auf `queued` setzen (Sicht-Signal fürs Polling). */
    private function markEnrichQueued(FoodAlchemistCascadeRunStep $step): void
    {
        $fresh = $step->fresh() ?? $step;
        $deferred = is_array($fresh->deferred) ? $fresh->deferred : [];
        $deferred['enrich'] = ['status' => 'queued', 'at' => now()->toIso8601String()];
        $fresh->update(['deferred' => $deferred]);
    }

    /** Bild-Erzeugungs-Status eines Rezept-/Gericht-Steps synchron auf `queued` setzen (Sicht-Signal fürs Polling). */
    private function markBilderQueued(FoodAlchemistCascadeRunStep $step): void
    {
        $fresh = $step->fresh() ?? $step;
        $deferred = is_array($fresh->deferred) ? $fresh->deferred : [];
        $deferred['bilder'] = ['status' => 'queued', 'at' => now()->toIso8601String()];
        $fresh->update(['deferred' => $deferred]);
    }

    /**
     * „Neu erzeugen" (Cockpit, Etappe 7 Teil 2b): stößt AUSSCHLIESSLICH die KI-Fotos eines bereits
     * freigegebenen Rezept-/Gericht-Steps erneut an — ohne Voll-Anreicherung — z. B. nachdem die
     * Bild-Erzeugung fehlschlug (deferred.bilder=failed). Der EnrichRecipeJob läuft im `nurBilder`-
     * Modus: er ersetzt die alten KI-Fotos und lässt deferred.enrich unangetastet.
     */
    public function reBilder(Team $team, int $stepId): void
    {
        $step = $this->ownedStep($team, $stepId);
        if ($step->ref_type !== 'recipe' || $step->ref_id === null || ! in_array($step->kind, ['rezept', 'gericht'], true)) {
            return;
        }
        $this->markBilderQueued($step);
        EnrichRecipeJob::dispatch($team->id, (int) (Auth::id() ?? 0), (int) $step->ref_id, null, false, (int) $step->id, true);
    }

    /**
     * Manuelles Foto (Cockpit, Etappe 7 Teil 2): ein hochgeladenes Bild als Rezept-Foto eines
     * Rezept-/Gericht-Steps übernehmen — die NICHT-KI-Alternative zur Foto-Erzeugung, OHNE KI-Call.
     * Owner-Guard über {@see ownedStep} (Freigabe/Schreiben nur durchs Besitzer-Team, D1); das Rezept
     * wird team-scoped aufgelöst. Delegiert an {@see RecipeImageService::uebernimmManuellesFoto}
     * (`$istErgebnis` → Hero statt Pool). Gibt das angelegte Foto zurück (oder null bei fremdem Step-Typ).
     */
    public function uebernimmManuellesFotoFuerStep(Team $team, int $stepId, \Illuminate\Http\UploadedFile $datei, bool $istErgebnis = false, ?string $caption = null): ?FoodAlchemistRecipeStepPhoto
    {
        $step = $this->ownedStep($team, $stepId);
        if ($step->ref_type !== 'recipe' || $step->ref_id === null || ! in_array($step->kind, ['rezept', 'gericht'], true)) {
            return null;
        }
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail((int) $step->ref_id);

        return app(RecipeImageService::class)->uebernimmManuellesFoto($team, $recipe, $datei, $caption, $istErgebnis);
    }

    /**
     * Foto-Wiederverwendung (Cockpit, Etappe 7 Teil 3b): ein bereits vorhandenes Team-Foto als
     * Rezept-Foto eines Rezept-/Gericht-Steps übernehmen — der Reuse-Picker-Endpunkt hinter dem
     * Service-Primitive {@see RecipeImageService::uebernimmVorhandenesFoto} (Teil 3a). Owner-Guard
     * über {@see ownedStep} (Schreiben nur durchs Besitzer-Team, D1); Ziel-Rezept UND Quell-Foto
     * werden team-scoped aufgelöst ({@see FoodAlchemistRecipeStepPhoto::visibleToTeam}) — ein fremdes
     * Quell-Foto findet der findOrFail gar nicht erst (zusätzlich zum Team-Guard im Primitive).
     * COPY-ON-REUSE (Design-Entscheid #105): die Quell-Bytes werden physisch in eine frische
     * ContextFile am Ziel kopiert, KEIN geteilter context_file_id → kein Lösch-Hazard; die Kopie
     * trägt keinen Kosten-Call-Log (überlebt den KI-Re-Trigger-Purge). `$istErgebnis` → Hero (max. 1).
     * Gibt das angelegte Foto zurück (oder null bei fremdem Step-Typ).
     */
    public function uebernimmVorhandenesFotoFuerStep(Team $team, int $stepId, int $quelleFotoId, bool $istErgebnis = false, ?string $caption = null): ?FoodAlchemistRecipeStepPhoto
    {
        $step = $this->ownedStep($team, $stepId);
        if ($step->ref_type !== 'recipe' || $step->ref_id === null || ! in_array($step->kind, ['rezept', 'gericht'], true)) {
            return null;
        }
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail((int) $step->ref_id);
        $quelle = FoodAlchemistRecipeStepPhoto::visibleToTeam($team)->findOrFail($quelleFotoId);

        return app(RecipeImageService::class)->uebernimmVorhandenesFoto($team, $recipe, $quelle, $caption, $istErgebnis);
    }

    /**
     * „Neu anreichern" (Cockpit): stößt die Anreicherung eines bereits freigegebenen Rezept-/Gericht-Steps
     * erneut an — z. B. nachdem der erste EnrichRecipeJob-Lauf fehlschlug (deferred.enrich=failed).
     */
    public function reAnreichern(Team $team, int $stepId): void
    {
        $step = $this->ownedStep($team, $stepId);
        if ($step->ref_type !== 'recipe' || $step->ref_id === null || ! in_array($step->kind, ['rezept', 'gericht'], true)) {
            return;
        }
        $params = is_array($step->run?->params) ? $step->run->params : [];
        $zielVk = isset($params['ziel_vk_eur']) ? (float) $params['ziel_vk_eur'] : null;
        $kiBilder = (bool) ($params['ki_bilder'] ?? false);
        $completeCoverage = array_key_exists('complete_coverage', $params) ? (bool) $params['complete_coverage'] : true;
        $this->markEnrichQueued($step);
        EnrichRecipeJob::dispatch($team->id, (int) (Auth::id() ?? 0), (int) $step->ref_id, $zielVk, $kiBilder, (int) $step->id, false, false, $completeCoverage);
    }

    /**
     * #6/Import (Dominique 2026-08-28): ein BEREITS bestehendes (importiertes) Rezept an den Worker
     * übergeben — Voll-Anreicherung inkl. LA-First-GP-Mint der offenen Zutaten, async via {@see EnrichRecipeJob}.
     * Das Artefakt existiert schon → Run+Step werden direkt als `done` angelegt (wie ein freigegebener Draft),
     * der Enrich-Fortschritt läuft über `deferred.enrich` (queued→running→done), das die Planung schon pollt.
     * So erscheint der Import als Worker-Lauf statt synchron im Request zu minten. Sub-Rezepte laufen im
     * Hintergrund mit (stepId=null, refresh — wie {@see \Platform\FoodAlchemist\Livewire\Recipes\RecipeModal::allesAnreichern}).
     */
    public function enrichBestehendesRezept(Team $team, int $recipeId, bool $vkModus, ?int $planningSessionId = null): FoodAlchemistCascadeRun
    {
        $recipe = FoodAlchemistRecipe::visibleToTeam($team)->findOrFail($recipeId);
        $kind = $vkModus ? 'gericht' : 'rezept';

        $run = FoodAlchemistCascadeRun::create([
            'team_id' => $team->id,
            'planning_session_id' => $planningSessionId,
            'scope' => $kind,
            'creative_mode' => 'voll_kreativ',
            'brief' => 'Import: ' . $recipe->name,
            'status' => 'done',                       // Artefakt existiert bereits — kein Generierungs-Schritt
            'staged' => false,
            'created_via' => 'import',
        ]);
        $step = FoodAlchemistCascadeRunStep::create([
            'team_id' => $team->id,
            'cascade_run_id' => $run->id,
            'parent_step_id' => null,
            'kind' => $kind,
            'ref_type' => 'recipe',
            'ref_id' => $recipe->id,
            'label' => Str::limit($recipe->name, 120),
            'status' => 'done',
            'sort' => 0,
        ]);

        $this->markEnrichQueued($step);
        EnrichRecipeJob::dispatch($team->id, (int) (Auth::id() ?? 0), (int) $recipe->id, null, false, (int) $step->id);

        // Sub-Rezepte im Hintergrund mit-anreichern (nicht am Worker-Step sichtbar, wie allesAnreichern).
        foreach (app(\Platform\FoodAlchemist\Services\RecipeOneShotService::class)->subRezeptIds((int) $recipe->id) as $subId) {
            EnrichRecipeJob::dispatch($team->id, (int) (Auth::id() ?? 0), (int) $subId, null, false, null, false, true);
        }

        return $run;
    }

    /**
     * „Neu generieren" (per-Step-KI im Cockpit): verwirft das aktuelle Draft-Artefakt und stößt die
     * Generierung dieses Steps erneut an (Brief = Step-Label, Params/Session/Staged vom Lauf). Nur
     * rezept|gericht|concept. Der Step geht zurück auf `running`; die Fläche pollt wie beim Go.
     */
    public function regeneriereStep(Team $team, int $stepId, ?string $kommentar = null): void
    {
        $step = $this->ownedStep($team, $stepId);
        if (! in_array($step->kind, ['rezept', 'gericht', 'concept'], true)) {
            return;
        }
        // L4: Regenerieren eines KIND-Basisrezepts — die Eltern-Zutat zeigt noch auf den gleich
        // gelöschten Draft. VOR dem Löschen die Bindung lösen (referenced_recipe_id NULL, unmatched),
        // die Dependency aber BEHALTEN, damit der neue Lauf via bindCompletedChild sauber neu bindet.
        // Sonst bliebe die Eltern-Zutat auf ein gelöschtes Rezept verdrahtet (tote Referenz).
        $elternRezeptIds = [];
        if ($step->ref_type === 'recipe' && $step->ref_id !== null) {
            $deps = FoodAlchemistCascadeRecipeDependency::where('cascade_run_id', $step->cascade_run_id)
                ->where('child_step_id', (int) $step->id)->get();
            foreach ($deps as $dep) {
                $zutat = FoodAlchemistRecipeIngredient::where('team_id', $team->id)->find((int) $dep->ingredient_id);
                if ($zutat !== null && (int) $zutat->referenced_recipe_id === (int) $step->ref_id) {
                    $elternRezeptIds[(int) $zutat->recipe_id] = true;
                    $zutat->update(['referenced_recipe_id' => null, 'match_method' => 'unmatched', 'match_confidence' => null]);
                }
            }
        }
        if ($step->ref_id !== null) {
            if ($step->ref_type === 'recipe') {
                FoodAlchemistRecipe::where('team_id', $team->id)->whereKey($step->ref_id)->delete();
            } elseif ($step->ref_type === 'concept') {
                FoodAlchemistConcept::where('team_id', $team->id)->whereKey($step->ref_id)->delete();
            }
        }
        // Eltern-Rezepte nach dem Lösen neu rechnen (EK/Allergene ohne die tote Zeile).
        foreach (array_keys($elternRezeptIds) as $rid) {
            app(RecipeRecomputeService::class)->recomputeAndPropagate((int) $rid);
        }
        // Die geplanten/übernommenen Sub-Rezepte beschreiben die Zerlegung des ALTEN Entwurfs — der
        // neue Lauf plant seine eigenen (sonst bleiben Zeilen stehen, die zu nichts mehr gehören).
        $this->raeumeGeplanteKinder($step);
        $step->update(['status' => 'running', 'ref_type' => null, 'ref_id' => null, 'error' => null, 'deferred' => null]);
        $run = $step->run;
        // L5: Wurzel-Step (Gericht/Concept) neu erzeugen aus dem VOLLEN Run-Brief — nicht aus dem Label,
        // das markStepDone inzwischen auf den (kurzen) Artefakt-Namen gezogen hat (sonst schrumpfte das
        // Briefing bei jedem „neu erzeugen"). Kind-Steps behalten ihr Label (= der Sub-Rezept-Name/Brief).
        $brief = $step->parent_step_id === null && trim((string) ($run?->brief ?? '')) !== ''
            ? (string) $run->brief
            : (string) ($step->label ?? '');
        // A2 (per-Speise-Feedback): ein gezielter Nutzer-Kommentar zu GENAU dieser Position wird als
        // Zusatz-Direktive an den Brief gehängt — die Regeneration baut nur diesen einen Entwurf nach
        // dem Feedback neu (die Nachbar-Positionen bleiben unberührt, weil regeneriereStep step-lokal ist).
        $kommentar = trim((string) $kommentar);
        if ($kommentar !== '') {
            $brief = rtrim($brief) . "\n\nGezielte Anpassung (Nutzer-Feedback zu dieser Position): " . $kommentar;
        }
        $params = is_array($run?->params) ? $run->params : [];
        $sessionId = $run?->planning_session_id !== null ? (int) $run->planning_session_id : null;
        $staged = (bool) ($run?->staged ?? false);
        if ($step->kind === 'concept') {
            // $params durchreichen — sonst verliert das „neu generieren" eines Concept-Steps SÄMTLICHE
            // Menü-Leitplanken (Gänge/Preis-Korridor/Quoten/Balance/Buffet), weil dispatchConceptStep
            // die menue_*-Teilmenge aus den Run-Params filtert (Default [] = kein Leitplanken-Erbe).
            // S3a: Attach-Args mitgeben, sonst dockt ein neu generiertes Kapitel-Concept NICHT mehr ans
            // Kapitel (stiller Datenverlust) — der Kapitel-Bezug steckt am Step (chapter_id).
            $attachOwnerType = ($step->chapter_id !== null && $run?->source_owner_type === 'foodbook') ? 'foodbook' : null;
            $attachContainerId = $attachOwnerType !== null ? (int) $step->chapter_id : null;
            $this->dispatchConceptStep($team, $step, $brief, $sessionId, (string) ($run?->creative_mode ?? 'voll_kreativ'), $params, $attachOwnerType, $attachContainerId);
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
        $this->raeumeGeplanteKinder($step);
        $step->update(['status' => 'verworfen']);
        $this->recomputeRunStatus((int) $step->cascade_run_id);
    }

    /**
     * Räumt die `geplant`/`skipped`-Kinder eines Steps weg (Verwerfen / Neu-Generieren): sie
     * beschreiben die Zerlegung eines Entwurfs, den es nicht mehr gibt. Es werden AUSSCHLIESSLICH
     * die Step-Zeilen entfernt — das bei `skipped` referenzierte Bestands-Rezept bleibt unangetastet
     * (es ist fremdes, lebendes Artefakt, kein Draft dieses Laufs).
     *
     * Hart gelöscht (nicht soft), weil der Unique-Index (cascade_run_id, dedupe_key) soft-gelöschte
     * Zeilen mitzählt — eine soft-gelöschte Planung würde die Neu-Planung desselben Sub-Rezepts
     * blockieren. Verlorene Historie: keine, diese Zeilen haben nie etwas erzeugt.
     */
    private function raeumeGeplanteKinder(FoodAlchemistCascadeRunStep $step): void
    {
        $kinder = FoodAlchemistCascadeRunStep::where('parent_step_id', $step->id)
            ->whereIn('status', ['geplant', 'skipped'])->get(['id']);
        if ($kinder->isEmpty()) {
            return;
        }
        $ids = $kinder->pluck('id')->all();
        FoodAlchemistCascadeRecipeDependency::whereIn('child_step_id', $ids)->delete();
        FoodAlchemistCascadeRunStep::whereIn('id', $ids)->forceDelete();
    }

    /**
     * „Jetzt erzeugen" je Zeile (Etappe 1, Teil 2): schaltet EINEN geplanten Sub-Rezept-Step scharf,
     * OHNE auf die Freigabe der Stufe darüber zu warten — der Mensch kann ein einzelnes Sub-Rezept
     * vorziehen. Nur `geplant`-Steps; die spätere Stufen-Freigabe dispatcht ihn nicht doppelt
     * ({@see RecipeDependencyWorkflowService::dispatchGeplantesKind}).
     */
    public function erzeugeGeplantenStep(Team $team, int $stepId): void
    {
        $step = $this->ownedStep($team, $stepId);
        if (app(RecipeDependencyWorkflowService::class)->dispatchGeplantesKind($team, $step)) {
            $this->recomputeRunStatus((int) $step->cascade_run_id);
        }
    }

    /**
     * „Brauche ich nicht" je Zeile (Etappe 1, Teil 2): verwirft EINEN geplanten Sub-Rezept-Step vor
     * seiner Erzeugung. Der Step wird als `verworfen` behalten (Tombstone), NICHT hart gelöscht — so
     * schaltet die spätere Stufen-Freigabe ({@see RecipeDependencyWorkflowService::dispatchChildren})
     * ihn über den `dedupe_key`-Treffer bewusst nicht scharf (Status ≠ `geplant` → übersprungen);
     * die Eltern-Zutat bleibt als flache Zeile stehen. Die Dependency wird entfernt (kein Late-Bind).
     */
    public function verwirfGeplantenStep(Team $team, int $stepId): void
    {
        $step = $this->ownedStep($team, $stepId);
        if ($step->status !== 'geplant') {
            return;
        }
        FoodAlchemistCascadeRecipeDependency::where('child_step_id', $step->id)->delete();
        $step->update(['status' => 'verworfen']);
        $this->recomputeRunStatus((int) $step->cascade_run_id);
    }

    /**
     * Manuell ein Basisrezept in die Basisrezepte-Stufe ergänzen (T2, Real-Abnahme): ein Sub-Rezept,
     * das die KI nicht als Komponente erkannt hat (z. B. ein fehlender Jus), wird von Hand nachgezogen.
     * Legt einen `geplant`-Sub-Step (kind=rezept) unter dem Wurzel-Step des Laufs an — genau die Form,
     * die {@see erzeugeGeplantenStep}/{@see RecipeDependencyWorkflowService::dispatchGeplantesKind} kennt
     * (Brief=label, Params aus dem Eltern-`deferred.children` bzw. den Lauf-Params). Der Nutzer erzeugt
     * ihn danach je Zeile mit „jetzt erzeugen". Team-owned (D1). Idempotent über `dedupe_key` (`manual:`+
     * Name) — dasselbe Basisrezept doppelt ergänzt teilt sich einen Step.
     */
    public function ergaenzeManuellenSubStep(Team $team, int $runId, string $name, ?int $parentStepId = null): ?FoodAlchemistCascadeRunStep
    {
        $run = $this->lauf($team, $runId);
        if ($run === null || ! $run->isOwnedBy($team)) {
            return null;
        }
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        // Anker-Step: explizit gewählt (Gericht/Rezept-Step des Laufs) oder der Wurzel-Step als Default.
        // So kann ein manuelles Basisrezept gezielt UNTER dem richtigen Gericht hängen (Concept-Fan-out).
        $anker = null;
        if ($parentStepId !== null) {
            $anker = $run->steps->first(fn ($s) => (int) $s->id === $parentStepId
                && in_array($s->kind, ['gericht', 'rezept'], true));
        }
        $anker ??= $run->steps->first(fn ($s) => $s->parent_step_id === null) ?? $run->steps->first();
        if ($anker === null) {
            return null;
        }
        $dedupe = 'manual:' . mb_strtolower($name);
        $existing = FoodAlchemistCascadeRunStep::where('cascade_run_id', $run->id)->where('dedupe_key', $dedupe)->first();
        if ($existing !== null) {
            return $existing;   // schon ergänzt → idempotent
        }
        $step = FoodAlchemistCascadeRunStep::create([
            'team_id' => $team->id,
            'cascade_run_id' => (int) $run->id,
            'parent_step_id' => (int) $anker->id,
            'depth' => ((int) $anker->depth) + 1,
            'kind' => 'rezept',
            'label' => Str::limit($name, 120, ''),
            'dedupe_key' => $dedupe,
            'status' => 'geplant',
            'sort' => (int) ($run->steps->max('sort') ?? 0) + 1,
        ]);
        // L4: Rückbindung herstellen — eine Eltern-Zutatenzeile (unmatched, Platzhalter) am Anker-Rezept
        // + die Dependency auf den neuen Kind-Step. OHNE das wird das später erzeugte Basisrezept NIE ans
        // Elterngericht gebunden (bekannte Wunde: referenced_recipe_id blieb NULL). Fail-soft: steht das
        // Anker-Rezept noch nicht (ref_id NULL), bleibt es beim reinen Step (alt), keine Rückbindung möglich.
        if ($anker->ref_type === 'recipe' && $anker->ref_id !== null) {
            $parentRecipe = FoodAlchemistRecipe::where('team_id', $team->id)->find((int) $anker->ref_id);
            if ($parentRecipe !== null) {
                try {
                    $einheitId = FoodAlchemistVocabEinheit::visibleToTeam($team)->where('slug', 'g')->value('id')
                        ?? FoodAlchemistVocabEinheit::visibleToTeam($team)->orderBy('id')->value('id');
                    $zutat = $parentRecipe->ingredients()->create([
                        'team_id' => $team->id,
                        'raw_text' => $name,
                        'display_name' => $name,
                        'quantity' => 0,   // Platzhalter — der Mensch trägt die Menge nach
                        'unit_vocab_id' => $einheitId,
                        'position' => (int) ($parentRecipe->ingredients()->max('position') ?? 0) + 1,
                        'match_method' => 'unmatched',
                        'auto_ground' => false,
                    ]);
                    FoodAlchemistCascadeRecipeDependency::firstOrCreate([
                        'team_id' => $team->id,
                        'cascade_run_id' => (int) $run->id,
                        'parent_step_id' => (int) $anker->id,
                        'ingredient_id' => (int) $zutat->id,
                    ], ['child_step_id' => (int) $step->id]);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[Planung] manuelles Sub-Rezept ohne Rückbindung angelegt (Zutat/Dependency übersprungen)', ['error' => $e->getMessage()]);
                    // D2 2026-08-18: nicht mehr NUR ins Log — sichtbar am Step markieren, damit der Mensch
                    // die fehlende Rückbindung sieht (sonst hängt das Sub-Rezept unbemerkt lose am Lauf,
                    // referenced_recipe_id bleibt NULL). Spiegelt den E-P0-Härtungsgrundsatz „nichts still schlucken".
                    $step->update(['error' => 'Nicht automatisch ans Gericht gebunden — Zutat + Menge am Elterngericht bitte manuell ergänzen.']);
                }
            }
        }
        $this->recomputeRunStatus((int) $run->id);

        return $step;
    }

    /** Bulk-Freigabe aller noch offenen (done) Steps eines Laufs. */
    public function gibRunFrei(Team $team, int $runId): void
    {
        $run = $this->lauf($team, $runId);
        // D1-Slice 3: sichtbarer, nicht besessener Lauf → sauberer No-op (siehe gibStufeFrei).
        if ($run === null || ! $run->isOwnedBy($team)) {
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
        // D1-Slice 3: sichtbarer, nicht besessener Lauf → sauberer No-op (siehe gibStufeFrei).
        if ($run === null || ! $run->isOwnedBy($team)) {
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

    /**
     * E1b (Spec 40): Owner-Kontext der Session für die Leitstelle — macht den Einbahn-Sprung zum
     * sichtbaren Round-Trip: WOFÜR wird hier geplant (Ausgabe-Modul + Name) + der Rückweg dorthin.
     * Liest den jüngsten Lauf der Session MIT Ausgabe-Owner (`source_owner_type`/`_id` — die sitzen
     * auf dem Lauf, nicht der Session) und löst Anzeige-Name + Rück-Route inkl. Deep-Link-Param auf.
     * `null`, wenn die Session keinen Ausgabe-Owner trägt (freie Cockpit-Planung) → dann kein Banner.
     *
     * @return array{owner_type:string, owner_id:int, typ_label:string, name:string, route:string, route_param:array<string,int>}|null
     */
    public function ownerKontext(Team $team, int $sessionId): ?array
    {
        $run = FoodAlchemistCascadeRun::visibleToTeam($team)
            ->where('planning_session_id', $sessionId)
            ->whereNotNull('source_owner_type')
            ->whereNotNull('source_owner_id')
            ->orderByDesc('id')->first();
        if ($run === null) {
            return null;
        }
        $type = (string) $run->source_owner_type;
        $id = (int) $run->source_owner_id;
        [$typLabel, $name, $route, $param] = match ($type) {
            'foodbook' => ['Foodbook', \Platform\FoodAlchemist\Models\FoodAlchemistFoodbook::visibleToTeam($team)->whereKey($id)->value('label'), 'foodalchemist.foodbooks.index', 'fb'],
            'speisekarte' => ['Speisekarte', \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::visibleToTeam($team)->whereKey($id)->value('name'), 'foodalchemist.speisekarte.index', 'sk'],
            'speiseplan' => ['Speiseplan', FoodAlchemistSpeiseplan::visibleToTeam($team)->whereKey($id)->value('name'), 'foodalchemist.speiseplan.index', 'sp'],
            'offer' => ['Angebot', \Platform\FoodAlchemist\Models\FoodAlchemistAngebot::visibleToTeam($team)->whereKey($id)->value('name'), 'foodalchemist.angebote.index', 'sel'],
            default => [null, null, null, null],
        };
        if ($route === null) {
            return null;
        }

        return [
            'owner_type' => $type,
            'owner_id' => $id,
            'typ_label' => $typLabel,
            'name' => (string) ($name !== null && $name !== '' ? $name : $typLabel . ' #' . $id),
            'route' => $route,
            'route_param' => [$param => $id],
        ];
    }

    /** Ein team-sichtbarer Lauf inkl. Steps (oder null). */
    public function lauf(Team $team, int $runId): ?FoodAlchemistCascadeRun
    {
        return FoodAlchemistCascadeRun::visibleToTeam($team)->with('steps')->find($runId);
    }

    /**
     * Etappe 9 (MCP): der READ-ONLY Kaskaden-Status eines Laufs als flache, KI-lesbare Struktur —
     * die Statuszahlen, die das Cockpit zeigt, aber headless. Team-gescopt über {@see lauf()}
     * (visibleToTeam = Read-Contract); `null`, wenn der Lauf für das Team nicht sichtbar ist.
     *
     * Rein ableitend, keine Erfindung: Lauf-Kopf + je Ebene (`kind`) ein Status-Aggregat + die
     * Einzel-Schritte (inkl. Anreicherungs-/Bild-Status aus `deferred`) + ein `hinweis`, der den
     * bestehenden Run-Status (running|review|done|failed) in einen Handlungs-Satz übersetzt
     * (Freigabe/Fortsetzen bleiben human-only, kein MCP-Trigger).
     *
     * @return array<string, mixed>|null
     */
    public function laufStatus(Team $team, int $runId): ?array
    {
        $run = $this->lauf($team, $runId);
        if ($run === null) {
            return null;
        }

        $steps = $run->steps->sortBy('sort')->values();

        $schritte = $steps->map(function (FoodAlchemistCascadeRunStep $s): array {
            $deferred = is_array($s->deferred) ? $s->deferred : [];
            $snapshot = is_array($s->context_snapshot) ? $s->context_snapshot : [];

            return array_filter([
                'id' => (int) $s->id,
                'ebene' => (string) $s->kind,
                'label' => (string) $s->label,
                'status' => (string) $s->status,
                'tiefe' => (int) $s->depth,
                'ref_type' => $s->ref_type,
                'ref_id' => $s->ref_id !== null ? (int) $s->ref_id : null,
                'parent_step_id' => $s->parent_step_id !== null ? (int) $s->parent_step_id : null,
                'anreicherung' => $deferred['enrich']['status'] ?? null,
                'bilder' => $deferred['bilder']['status'] ?? null,
                'fehler' => $s->error,
                // E-P0 (Spec 40): Attach-Fehler auch headless sichtbar — das Konzept ist erzeugt, hängt aber
                // nicht am Ausgabe-Kapitel/der Rubrik (behebbar per haengeKonzeptNach). Nur gesetzt, wenn offen.
                'attach_fehler' => $deferred['attach_error'] ?? null,
                // Verwendetes Wissen je Step (aus context_snapshot, geschrieben von RecipeGenerationContextService::build):
                // welche Wissens-Dossiers real in den Prompt geflossen sind — damit die Erdung headless prüfbar ist.
                'wissen' => ! empty($snapshot['knowledge_files']) ? $snapshot['knowledge_files'] : null,
            ], static fn ($v): bool => $v !== null && $v !== '');
        })->all();

        $stufen = $steps->groupBy('kind')->map(static function ($group, $kind): array {
            return [
                'ebene' => (string) $kind,
                'gesamt' => $group->count(),
                'geplant' => $group->where('status', 'geplant')->count(),
                'laufend' => $group->whereIn('status', ['queued', 'running'])->count(),
                'entwurf_offen' => $group->where('status', 'done')->count(),   // fertiger Draft, wartet auf Freigabe (Gate 2)
                'freigegeben' => $group->where('status', 'freigegeben')->count(),
                'uebernommen' => $group->where('status', 'skipped')->count(),   // Reuse-Treffer
                'verworfen' => $group->where('status', 'verworfen')->count(),
                'fehlgeschlagen' => $group->where('status', 'failed')->count(),
            ];
        })->values()->all();

        // Die real wirksamen Leitplanken dieses Laufs: run.params ist die am START eingefrorene Kopie der
        // Session-generation_params — gegen die Whitelist gefiltert, damit nur die Regler (nicht die
        // Flow-Steuer-Keys owner_type/cascade_step_id/…) sichtbar werden. So ist per MCP prüfbar, WOMIT
        // der Lauf lief (Kern des „Leitplanken prüfen").
        $laufParams = is_array($run->params) ? $run->params : [];
        $leitplanken = array_intersect_key(
            $laufParams,
            array_flip(FoodAlchemistPlanningSession::ALLOWED_GENERATION_PARAMS)
        );

        return [
            'lauf' => array_filter([
                'id' => (int) $run->id,
                'scope' => (string) $run->scope,
                'status' => (string) $run->status,
                'gestuft' => (bool) $run->staged,
                'creative_mode' => (string) $run->creative_mode,
                'planning_session_id' => $run->planning_session_id !== null ? (int) $run->planning_session_id : null,
                'origin_dish_idea_id' => $run->origin_dish_idea_id !== null ? (int) $run->origin_dish_idea_id : null,
            ], static fn ($v): bool => $v !== null && $v !== ''),
            'leitplanken' => $leitplanken === [] ? null : $leitplanken,
            'stufen' => $stufen,
            'schritte' => $schritte,
            'kohaerenz_warnung' => is_array($run->cohesion_warning) ? $run->cohesion_warning : null,
            'hinweis' => $this->laufStatusHinweis((string) $run->status),
        ];
    }

    /** Übersetzt den Run-Status in einen Handlungs-Satz (Freigabe/Fortsetzen bleiben human-only). */
    private function laufStatusHinweis(string $status): string
    {
        return match ($status) {
            'running' => 'Der Worker rechnet noch (Steps queued/running). Auf Abschluss warten, dann prüfen/freigeben.',
            'review' => 'Fertige Entwürfe warten auf die menschliche Freigabe (Gate 2); geplante Sub-Rezepte auf die Freigabe der Stufe darüber. Freigeben/Verwerfen ist human-only, kein MCP-Trigger.',
            'done' => 'Lauf abgeschlossen — alle Artefakte freigegeben oder als Bestand übernommen.',
            'failed' => 'Lauf fehlgeschlagen — gescheiterte Schritte lassen sich gebündelt fortsetzen (human-only im Cockpit).',
            default => $status,
        };
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
