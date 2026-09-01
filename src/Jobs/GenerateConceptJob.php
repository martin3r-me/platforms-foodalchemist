<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;

/**
 * Async-Konzept-Generierung für die Planungs-Kaskade (P1a).
 *
 * Spiegelt {@see GenerateRecipeJob}: der Concept-Assembler ruft `AiGatewayService::propose`
 * (Gerüst) + den deterministischen Assembler — im synchronen Web-Request derselbe
 * nginx-fastcgi-Timeout-/502-Risiko wie beim Rezept. Also in die Queue, UI pollt den Step.
 *
 * **Plan-first (#53, kreative Modi voll_kreativ/hybrid):** `planAusBrief` baut Draft + kreative
 * Canvas + LEERE Fan-out-Slots; die Gerichte erfindet der Gericht-Fan-out bei der Freigabe.
 * **Reuse-Modus (datenbank):** `generiereAusBrief` baut das Konzept AUSSCHLIESSLICH aus echten
 * VK-Gerichten (keine Erfindung — Slot ohne Treffer bleibt leer). Beide erben die Menü-Achsen.
 * Ergebnis ist immer `status=draft`.
 */
class GenerateConceptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Gerüst-Propose (~1 Call) + deterministischer Assembler — unter dem Worker-Timeout (600 s). */
    public int $timeout = 180;

    /** KI-Kosten: kein stiller Auto-Retry. */
    public int $tries = 1;

    public function __construct(
        public string $runId,
        public int $teamId,
        public int $userId,
        public string $brief,
        public ?string $name = null,
        public ?int $planningSessionId = null,
        public ?int $cascadeStepId = null,
        public string $creativeMode = 'datenbank',
        public bool $useFavorites = false,
        public bool $favoritesConvenienceOnly = false,
        /** Voll-Kaskade (P3/P4): erzeugtes Konzept ans Ausgabe-Kapitel/-Rubrik hängen. */
        public ?string $attachOwnerType = null,
        public ?int $attachContainerId = null,
        /** Concept-Tab Menü-Leitplanken (kanonische menue_*_pp-Keys); speisen den Gerüst-Kopf. */
        public array $menueAchsen = [],
    ) {}

    public static function cacheKey(string $runId): string
    {
        return "fa:concept-gen:{$runId}";
    }

    public function handle(ConceptGeneratorService $generator): void
    {
        if ($this->kaskadeAbgebrochen()) {
            $this->schreibe(['status' => 'cancelled']);
            return;
        }
        $team = Team::find($this->teamId);
        $user = User::find($this->userId);
        if ($team === null || $user === null) {
            $this->schreibe(['status' => 'error', 'fehler' => 'Team oder User nicht gefunden.']);
            $this->meldeKaskade(false, null, 'Team oder User nicht gefunden.');

            return;
        }

        Auth::login($user);   // Team-Kontext für AiGatewayService (Kill-Switch/DNA/Call-Log)

        try {
            // #53 „Standard plan-first": in den kreativen Modi (voll_kreativ/hybrid) baut der Concept-Go
            // den vollen Plan (Draft + kreative Canvas + LEERE Fan-out-Slots) — die Gerichte ERFINDET
            // der Fan-out bei der Freigabe. Nur der Reuse-Modus (datenbank) füllt weiter deterministisch
            // aus dem VK-Bestand (Assembler); Invention widerspräche seinem Zweck. Beide erben die
            // Menü-Achsen (menueAchsen). Der Fan-out-Zweig unten gilt ohnehin nur für die kreativen Modi.
            $planFirst = in_array($this->creativeMode, ['voll_kreativ', 'hybrid'], true);
            $r = $planFirst
                ? $generator->planAusBrief($team, $this->brief, [], $this->name, 'plan_go', $this->menueAchsen)
                : $generator->generiereAusBrief($team, $this->brief, $this->name, 'plan_go', $this->useFavorites, $this->favoritesConvenienceOnly, $this->menueAchsen);
            $concept = $r['concept'] ?? null;
            if ($concept === null) {
                throw new \RuntimeException('Konzept-Generierung lieferte kein Ergebnis.');
            }
            if ($this->kaskadeAbgebrochen()) {
                $concept->delete();
                $this->schreibe(['status' => 'cancelled']);
                return;
            }
            // Planungs-„Go"-Lineage: Trend-Herkunft ans Konzept, Session→konvergenz.
            if ($this->planningSessionId !== null) {
                $planSvc = app(\Platform\FoodAlchemist\Services\PlanningSessionService::class);
                $session = $planSvc->get($team, $this->planningSessionId);
                if ($session !== null) {
                    $planSvc->verknuepfeArtefakt($session, 'concept', (int) $concept->id);
                }
            }
            $this->schreibe([
                'status' => 'done',
                'concept_id' => (int) $concept->id,
                'name' => (string) $concept->name,
                'coverage' => $r['coverage'] ?? null,
            ]);
            // Voll-Kaskade (P3/P4): das erzeugte Konzept ans Ausgabe-Kapitel/-Rubrik hängen (concept_ref/menue_ref).
            $this->attachToOutput($team, (int) $concept->id);
            // P1b: in den Erfinden-Modi fächert das Konzept in erfundene Gerichte auf (je leerem Slot
            // eine KI-Idee → eigener Kind-Step + Materialisierungs-Job). Reuse-Modus (datenbank) tut nichts.
            // Muss VOR meldeKaskade laufen (dessen recompute soll die Kind-Steps schon sehen). Graceful:
            // ohne LLM/Slots bleibt es beim Konzept — der Run geht dann direkt auf review.
            if ($this->cascadeStepId !== null && in_array($this->creativeMode, ['voll_kreativ', 'hybrid'], true)) {
                try {
                    // Ursprungs-Trend der Planung (falls vorhanden) fließt in die Erfindungs-Divergenz.
                    $trendDocId = null;
                    if ($this->planningSessionId !== null) {
                        $sess = app(\Platform\FoodAlchemist\Services\PlanningSessionService::class)->get($team, $this->planningSessionId);
                        $trendDocId = $sess?->source_knowledge_document_id !== null ? (int) $sess->source_knowledge_document_id : null;
                    }
                    $step = \Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep::find($this->cascadeStepId);
                    if ((bool) ($step?->run?->staged ?? false)) {
                        // Gestuft (Gate pro Ebene): NICHT jetzt fächern — die Freigabe des Concept-Steps
                        // startet den Gericht-Fan-out ({@see FanoutConceptJob}). Args am Step ablegen.
                        $step?->update(['deferred' => ['fanout' => [
                            'mode' => $this->creativeMode,
                            'trend_doc_id' => $trendDocId,
                            'planning_session_id' => $this->planningSessionId,
                        ]]]);
                    } else {
                        app(\Platform\FoodAlchemist\Services\PlanningCascadeService::class)
                            ->fanoutConceptInvention($team, $this->cascadeStepId, (int) $concept->id, $this->creativeMode, $trendDocId, $this->planningSessionId);
                    }
                } catch (\Throwable) {
                    // Fan-out-Fehler darf das erzeugte Konzept nicht kippen.
                }
            }
            $this->meldeKaskade(true, (int) $concept->id, null);
        } catch (\Throwable $e) {
            $this->schreibe(['status' => 'error', 'fehler' => $e->getMessage()]);
            $this->meldeKaskade(false, null, $e->getMessage());
        }
    }

    /** Job-Tod (Timeout/Fatal außerhalb des handle-try) → Status trotzdem setzen. */
    public function failed(\Throwable $e): void
    {
        $this->schreibe(['status' => 'error', 'fehler' => 'Konzept-Generierung abgebrochen: ' . $e->getMessage()]);
        $this->meldeKaskade(false, null, 'Konzept-Generierung abgebrochen: ' . $e->getMessage());
    }

    /**
     * Voll-Kaskade-Attach: hängt das erzeugte Konzept an sein Ausgabe-Kapitel (Foodbook, concept_ref-Block)
     * bzw. seine Rubrik (Speisekarte, menue_ref-Position). No-op ohne attach-Info (Standalone/Depth-1). Wirft nie.
     *
     * **E-P0 (Spec 40):** Ein Attach-Fehler darf das erzeugte Konzept nicht kippen — aber er wird auch NICHT
     * mehr still geschluckt (sonst existierte das Konzept frei, aber NICHT im Kapitel/der Rubrik, ohne jedes
     * Signal → latenter Datenverlust). Ein einmaliger Retry fängt transiente Fehler (Lock/Deadlock); scheitert
     * es endgültig, wird der Fehler geloggt + als behebbares Signal am Step festgehalten
     * ({@see PlanningCascadeService::markAttachFailed} → Cockpit zeigt es, {@see …::haengeKonzeptNach} holt nach).
     */
    private function attachToOutput(\Platform\Core\Models\Team $team, int $conceptId): void
    {
        if ($this->attachOwnerType === null || $this->attachContainerId === null) {
            return;
        }
        try {
            $this->attachEinmal($team, $conceptId);
        } catch (\Throwable $e) {
            // Einmaliger Retry — ein transienter Fehler (DB-Lock/Deadlock) darf nicht gleich zum Signal werden.
            // Sicher gegen Doppel-Anhängen: addBlock/addPosition legen erst nach erfolgreicher Validierung an;
            // wirft der erste Versuch, wurde nichts persistiert.
            try {
                $this->attachEinmal($team, $conceptId);

                return;
            } catch (\Throwable $e2) {
                $e = $e2;
            }
            \Illuminate\Support\Facades\Log::warning('[GenerateConceptJob] Attach ans Ausgabe-Kapitel/die Rubrik fehlgeschlagen', [
                'concept' => $conceptId,
                'owner_type' => $this->attachOwnerType,
                'container_id' => $this->attachContainerId,
                'step' => $this->cascadeStepId,
                'error' => $e->getMessage(),
            ]);
            if ($this->cascadeStepId !== null) {
                try {
                    app(\Platform\FoodAlchemist\Services\PlanningCascadeService::class)
                        ->markAttachFailed($this->cascadeStepId, (string) $this->attachOwnerType, (int) $this->attachContainerId, $conceptId, $e->getMessage());
                } catch (\Throwable) {
                    // Rückkanal-Fehler dürfen den Job-Erfolg nicht kippen — der Log oben steht bereits.
                }
            }
        }
    }

    /** Ein Attach-Versuch (Foodbook-Block bzw. Speisekarte-Position). Wirft bei Fehler — Retry/Recording liegt beim Aufrufer. */
    private function attachEinmal(\Platform\Core\Models\Team $team, int $conceptId): void
    {
        if ($this->attachOwnerType === 'foodbook') {
            app(\Platform\FoodAlchemist\Services\FoodbookService::class)
                ->addBlock($team, $this->attachContainerId, ['type' => 'concept_ref', 'concept_id' => $conceptId]);
        } elseif ($this->attachOwnerType === 'speisekarte') {
            app(\Platform\FoodAlchemist\Services\SpeisekarteService::class)
                ->addPosition($team, $this->attachContainerId, ['type' => 'menue_ref', 'concept_id' => $conceptId]);
        } elseif ($this->attachOwnerType === 'offer') {
            // E2 (Spec 40): das erzeugte (standalone) Konzept ans Angebot referenzieren — Pivot
            // foodalchemist_offer_concept. attachContainerId ist die Angebots-ID (kein Zwischen-Container).
            app(\Platform\FoodAlchemist\Services\AngebotService::class)
                ->referenziereConcept($team, $this->attachContainerId, $conceptId);
        } elseif ($this->attachOwnerType === 'format') {
            // Format (gebrandetes Foodkonzept): das erzeugte Concept als Aufbau-Slot (type=concept) ins Format
            // referenzieren — attachContainerId ist die format_id (Container = Format selbst, wie beim Angebot).
            app(\Platform\FoodAlchemist\Services\FormatService::class)
                ->slotConceptEinfuegen($team, $this->attachContainerId, $conceptId);
        }
    }

    /** Ergebnis/Fehler an den Kaskaden-Step zurückmelden (No-op ohne cascadeStepId). Wirft nie. */
    private function meldeKaskade(bool $erfolg, ?int $conceptId, ?string $fehler): void
    {
        if ($this->cascadeStepId === null) {
            return;
        }
        try {
            $svc = app(\Platform\FoodAlchemist\Services\PlanningCascadeService::class);
            if ($erfolg && $conceptId !== null) {
                $svc->markStepDone($this->cascadeStepId, 'concept', $conceptId);
            } else {
                $svc->markStepFailed($this->cascadeStepId, (string) ($fehler ?? 'Konzept-Generierung fehlgeschlagen.'));
            }
        } catch (\Throwable) {
            // Rückkanal-Fehler bewusst schlucken.
        }
    }

    private function kaskadeAbgebrochen(): bool
    {
        if ($this->cascadeStepId === null) {
            return false;
        }
        $runId = \Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep::whereKey($this->cascadeStepId)->value('cascade_run_id');

        return $runId !== null
            && app(\Platform\FoodAlchemist\Services\PlanningCascadeService::class)->istAbgebrochen((int) $runId);
    }

    private function schreibe(array $data): void
    {
        Cache::put(self::cacheKey($this->runId), $data, now()->addMinutes(15));
    }
}
