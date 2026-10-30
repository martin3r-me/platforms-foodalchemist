<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Ai\KiAuftragService;
use Platform\FoodAlchemist\Services\Ai\KiExternModus;

/**
 * #1002 · Umleitung: ein MCP-Aufruf eines KI-Tools läuft extern. Das Tool bekommt den KI-Auftrag statt eines OpenAI-Calls,
 * der Client beantwortet ihn (ki_auftraege.GET → ANTWORT) und wiederholt den Aufruf.
 *
 * Der Haken sitzt in {@see \Platform\FoodAlchemist\Tools\FaRechteToolHuelle} (wickelt jedes FA-Tool). Nur Aufrufe über den externen
 * MCP-Server ({@see vomExternenMcp}) und nur Tools in {@see TOOLS} werden umgeleitet — die Plattform-KI (callWithTools, Core-Chat)
 * ruft dieselben Tools und bekommt weiter ihr Ergebnis — jedes dort mit Begründung und eigenem Test (tests/Feature/KiUmleitungTest.php). Der Lauf steht in einer
 * Transaktion: wartet er auf einen Auftrag, rollt sie zurück (kein Halbzustand), danach wird der Auftrag außerhalb der
 * Transaktion angelegt. Der Rollback deckt nur die Datenbank. Tools mit Nebenwirkungen außerhalb der DB vor oder zwischen
 * `propose()` (Queue-Dispatch, Qdrant/Embedding, Dateien, Events, Mails, Cache) sind Klasse c und stehen in {@see AUSGENOMMEN},
 * bis sie afterCommit/idempotent sind.
 */
final class KiUmleitung
{
    /**
     * Umgeleitete Tools → Begründung (geprüft: Pfad Tool → Service ohne Queue/Event/Cache/Storage/Http/Mail).
     *
     * @var array<string, string>
     */
    public const TOOLS = [
        'foodalchemist.concept_wording.GENERATE' => 'Ein propose() (concept.wording) je Slot/Kopf, danach DB-Schreiben der Texte (ConceptService::generateWording, keine Folge-Jobs).',
        'foodalchemist.angebot.KUNDENTEXT_GENERATE' => 'Ein propose() (foodbook.kundentext) über AngebotService, liefert nur den Vorschlag, schreibt nichts.',
        'foodalchemist.foodbook.KUNDENTEXT_GENERATE' => 'Ein propose() (foodbook.kundentext) über FoodbookService, liefert nur den Vorschlag, schreibt nichts.',
        'foodalchemist.foodbook_kapitel.KUNDENTEXT_GENERATE' => 'Ein propose() (foodbook.kundentext) über FoodbookService, liefert nur den Vorschlag, schreibt nichts.',
        'foodalchemist.gp_forms.ESTIMATE' => 'Ein propose() (gp.zaehl_einheiten), danach nur DB-Schreiben der Formen.',
        'foodalchemist.la.GP_ABGLEICH' => 'Bis zu zwei propose() nacheinander (gp.suggest für die Felder, danach gp.la_abgleich); der zweite Prompt hängt von der ersten Antwort ab, darum je Runde ein Auftrag. Schreibt nur DB (Abgleich-Log, bei apply Verknüpfung/neues GP), keine Queue-Jobs im Abgleich-Pfad.',
        'foodalchemist.presentation_designs.GENERATE_CSS' => 'Ein propose() (praesentation.design_css), liefert nur den Vorschlag, schreibt nicht.',
        'foodalchemist.planung_leitplanken.EXTRACT' => 'Ein propose() (planung.leitplanken), schreibt höchstens die Regler der Planungssitzung (DB).',
        'foodalchemist.planung_vorschlag.POST' => 'Ein propose() (planung.leitplanken) über BriefingLeitplankenService, schreibt nichts (Vorschlag).',
        'foodalchemist.recipe_coherence.POST' => 'Ein propose() je Modus (vk.kohaerenz / vk.teller_heber), danach DB-Schreiben der Bewertung.',
        'foodalchemist.recipe_klasse.POST' => 'Ein propose() (vk.speisen_klasse), optional DB-Übernahme (accept). Das Tool fängt Throwable: darum meldet die Hülle über den Modus, nicht über die Ausnahme.',
        'foodalchemist.recipe_rollen.POST' => 'Ein propose() (vk.rollen), optional DB-Übernahme (accept).',
        'foodalchemist.verkaufsrezepte.REVISE' => 'Ein propose() (vk.ueberarbeiten), optional DB-Übernahme (accept); Folge-Job SensorikNachholen erbt den Extern-Modus.',
        'foodalchemist.recipe_sensorik.POST' => 'Ein propose() (recipe.sensorik), danach DB-Schreiben des Profils.',
        'foodalchemist.recipes.REVIEW' => 'Read-only: ein propose() (recipe.review / vk.review) über RecipeReviewService, schreibt nichts.',
        'foodalchemist.recipes.REVISE' => 'Ein propose() (recipe.ueberarbeiten), optional DB-Übernahme (accept) über RecipeService — dessen Folge-Job SensorikNachholen erbt den Extern-Modus (P2c-1).',
        'foodalchemist.concepts.GENERATE' => 'Brief-Pfad: propose() concept.brief_geruest, danach Gerüst-Befüllung (concept.plan je Bedarf) und Wording-Pass (concept.wording, fail-soft) — jede Runde in der Tool-Transaktion, Rollback bis alles beantwortet ist; nur DB-Schreiben, keine Queue-Jobs. Gerüst-Pfad ohne KI bis auf den Wording-Pass.',
        'foodalchemist.foodbook_kapitel.WORDING_GENERATE' => 'Je Konzept-Block ein propose() (concept.wording, Positionen des Kapitels), danach DB-Schreiben der Overrides am Block; ohne Kapitel-Schreibstil oder ohne Slots kein KI-Aufruf.',
        'foodalchemist.speisekarte_wording.GENERATE' => 'Je Gericht-/Menü-Position ein propose() (foodbook.kundentext), fail-soft je Position, danach DB-Schreiben von wording; ohne Schreibstil der Karte kein KI-Aufruf.',
        'foodalchemist.foodbook.PLAN_FROM_BRIEF' => 'Gerüst-propose() (geruestAusBriefFuerOwner) im Tool-Aufruf, Rollback bis beantwortet; danach startet starteKaskade die Kaskade: ihre Jobs (Paket Kaskade: KiExternWiederholbar, erben extern aus dem MCP-Aufruf, Dispatch erst nach dem Commit) legen die weiteren Aufträge an.',
        'foodalchemist.angebot.PLAN_FROM_BRIEF' => 'Gerüst-propose() (geruestAusBriefFuerOwner) im Tool-Aufruf, Rollback bis beantwortet; danach startet starteKaskade die Kaskade: ihre Jobs (Paket Kaskade: KiExternWiederholbar, erben extern aus dem MCP-Aufruf, Dispatch erst nach dem Commit) legen die weiteren Aufträge an.',
        'foodalchemist.format.PLAN_FROM_BRIEF' => 'Gerüst-propose() (geruestAusBriefFuerOwner) im Tool-Aufruf, Rollback bis beantwortet; danach startet starteKaskade die Kaskade: ihre Jobs (Paket Kaskade: KiExternWiederholbar, erben extern aus dem MCP-Aufruf, Dispatch erst nach dem Commit) legen die weiteren Aufträge an.',
        'foodalchemist.speisekarte.PLAN_FROM_BRIEF' => 'Gerüst-propose() (geruestAusBriefFuerOwner) im Tool-Aufruf, Rollback bis beantwortet; danach startet starteKaskade die Kaskade: ihre Jobs (Paket Kaskade: KiExternWiederholbar, erben extern aus dem MCP-Aufruf, Dispatch erst nach dem Commit) legen die weiteren Aufträge an.',
        // foodalchemist.speiseplan.PLAN_FROM_BRIEF: kein Gerüst-Aufruf im Tool, die KI läuft nur in den Kaskaden-Jobs (MaterializeSpeiseplanCellJob, KiExternWiederholbar) — das Tool läuft normal, die Jobs erben extern aus dem MCP-Aufruf.
        'foodalchemist.recipes.EXTRACT' => 'Nur der Textweg (raw_text; das Tool nimmt weder Bild noch PDF): ein propose() (recipe.extract), danach DB-Schreiben des Entwurfs inkl. Sub-Basisrezepte. Folge-Jobs (StubAusrezeptierenJob für neue Hüllen) erben extern aus dem MCP-Aufruf und laufen erst nach dem Commit. dry_run schreibt nichts.',
        'foodalchemist.produktpass.RECHERCHE' => 'Websuche (Option der Websuche am Gateway): ein propose() (artikel.web_recherche) über ArtikelWebRecherche, danach DB-Schreiben des Vorschlags. Extern sucht der MCP-Client selbst; die Antwort muss quellen tragen (sonst abgelehnt), sie kommen als `sources` zurück. Keine Queue-Jobs.',
        'foodalchemist.signale.FIX' => 'Nur der Assist-Plan ruft ein propose() (je Signal-Typ signal.* / price.plausi) und liefert einen Entwurf ohne Schreiben und ohne Schließen. Deterministische Pläne, Weg-Absagen und dry_run rufen keine KI und laufen unverändert (SignalFixJob ist rein deterministisch).',
        'foodalchemist.recipes.GENERATE' => 'Synchron: Rezept, Zutaten und Matching entstehen im Tool-Aufruf (Transaktion ⇒ der Rollback nimmt den Entwurf zurück, jede Wiederholung startet vom selben Stand). Mehrere KI-Glieder = mehrere Runden. Nebenwirkungen außerhalb der DB meldet die Wache (KiUmleitungTest/KiExternGenerateTest).',
    ];

    /**
     * Bewusst NICHT umgeleitet → Grund. Gilt, bis der Grund behoben ist.
     *
     * @var array<string, string>
     */
    public const AUSGENOMMEN = [
    ];

    /**
     * Herkunfts-Marker: Der Core-MCP-Adapter (`Mcp\Adapters\ToolContractAdapter::createContextFromRequest`, auch für das
     * Meta-Tool `execute`) setzt `metadata['team_source']` IMMER (mcp_session | user_current | none). Alle internen Wege
     * bauen ihren ToolContext ohne Metadaten: `AiGatewayService::callWithTools` (Voice-/Tier-D-Loop), `VoiceModal`,
     * Core-KI-Chat (`ToolContext::fromAuth()`), Flow-`ToolAutomation`. Nur ein Aufruf mit Marker kommt vom externen MCP-Server.
     */
    public const HERKUNFT_MARKER = 'team_source';

    /**
     * Hängt am Core-Detail, dass nur `platform-core/src/Mcp/Adapters/ToolContractAdapter.php::createContextFromRequest()` den Key
     * `team_source` setzt. Ändert der Core ihn, fällt die Umleitung fail-safe auf den Plattformweg zurück (kein Auftrag statt
     * Ergebnis), und KiUmleitungTest wird rot.
     */
    public static function vomExternenMcp(ToolContext $context): bool
    {
        return array_key_exists(self::HERKUNFT_MARKER, $context->metadata);
    }

    /**
     * Tools, deren KI-Aufruf im Queue-Worker läuft (Lauf-Flag statt Hülle): Der externe MCP-Aufruf setzt `extern` am Lauf, der Worker
     * legt dann KI-Aufträge an. Die Hülle (Transaktion/Rollback) greift hier nicht, der Tool-Aufruf selbst schreibt nur die Lauf-Zeile.
     *
     * @var array<string, string>
     */
    public const LAUF_FLAG = [
        'foodalchemist.gps.ENRICH' => 'BulkEnrichService::starteGp(extern) → BulkEnrichGpJob setzt KiExternModus im Worker; verarbeiteGp legt bei „ausstehend“ keinen Leer-Vorschlag an.',
        'foodalchemist.recipes.ENRICH' => 'PlanningCascadeService::enrichBestehendesRezept(extern) → EnrichRecipeJob (KiExternWiederholbar, P2b): Sammel-Transaktion im Job, ANTWORT startet den Lauf neu.',
    ];

    /** Soll ein Lauf, den dieser Aufruf startet, extern laufen? (nur Aufruf über den externen MCP-Server, Schalter an) */
    public static function externFuerLauf(ToolContext $context): bool
    {
        return (bool) config('foodalchemist.ai.mcp_extern', true) && self::vomExternenMcp($context);
    }

    public static function umleiten(string $toolName, ToolContext $context): bool
    {
        return isset(self::TOOLS[$toolName]) && (bool) config('foodalchemist.ai.mcp_extern', true) && self::vomExternenMcp($context);
    }

    /**
     * Ein externer MCP-Aufruf hat Folge-Jobs angestoßen (z. B. GP-Aroma nach gps.POST), die extern laufen: ihre KI-Aufträge entstehen
     * erst im Worker (teils verzögert). Das Ergebnis sagt es, damit sie nicht unbemerkt bis zum Ablauf (24 h) liegen bleiben.
     */
    public static function mitFolgeHinweis(ToolResult $ergebnis, int $folgeJobs): ToolResult
    {
        if ($folgeJobs <= 0 || ! $ergebnis->success || ! is_array($ergebnis->data)) {
            return $ergebnis;
        }

        return new ToolResult(true, $ergebnis->data + ['folgeauftraege' => [
            'jobs_angestossen' => $folgeJobs,
            'hinweis' => 'Der Aufruf hat '.$folgeJobs.' Folge-Job(s) angestoßen, die KI-Aufträge anlegen (teils verzögert, bis zu einigen Minuten). '
                .'foodalchemist.ki_auftraege.GET zeigt sie, ki_auftraege.ANTWORT beantwortet sie; unbeantwortet laufen sie nach 24 Stunden ab.',
        ]], $ergebnis->error, $ergebnis->errorCode, $ergebnis->metadata);
    }

    /** @param callable(): ToolResult $lauf */
    public static function ausfuehren(callable $lauf, string $toolName = 'tool'): ToolResult
    {
        $db = DB::connection();
        $modus = app(KiExternModus::class);
        $niveau = $db->transactionLevel();
        \Platform\FoodAlchemist\Services\Ai\KiExternLauf::wacheStart();   // Nicht-DB-Nebenwirkungen vor dem Rollback sichtbar machen
        $db->beginTransaction();
        $ergebnis = null;
        $fehler = null;
        try {
            $ergebnis = $modus->withinSammelnd($lauf);
        } catch (\Throwable $e) {
            $fehler = $e;
        }
        \Platform\FoodAlchemist\Services\Ai\KiExternLauf::wacheEnde("tool:{$toolName}");
        $merker = $modus->entnimm();

        if ($merker['wartet'] === []) {
            if ($fehler !== null) {
                $db->rollBack($niveau);

                throw $fehler;
            }
            $db->commit();

            return $ergebnis;
        }

        // wartet auf Antwort: DB-Stand zurück, Auftrag danach (außerhalb der Transaktion) anlegen
        $db->rollBack($niveau);
        app(KiAuftragService::class)->legeAn($merker['vorgemerkt']);

        return ToolResult::success([
            'status' => 'ki_auftrag_offen',
            'auftraege' => array_map(fn (string $uuid, string $feature) => ['auftrag_id' => $uuid, 'feature' => $feature],
                array_keys($merker['wartet']), array_values($merker['wartet'])),
            'hinweis' => 'Nichts wurde geschrieben. foodalchemist.ki_auftraege.GET holt den Auftrag, ki_auftraege.ANTWORT beantwortet ihn — danach denselben Aufruf wiederholen.',
        ]);
    }
}
