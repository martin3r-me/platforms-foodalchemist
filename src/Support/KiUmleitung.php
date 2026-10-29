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
        'foodalchemist.gp_forms.ESTIMATE' => 'Ein propose() (gp.zaehl_einheiten), danach nur DB-Schreiben der Formen.',
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
        'foodalchemist.recipes.GENERATE' => 'Synchron: Rezept, Zutaten und Matching entstehen im Tool-Aufruf (Transaktion ⇒ der Rollback nimmt den Entwurf zurück, jede Wiederholung startet vom selben Stand). Mehrere KI-Glieder = mehrere Runden. Nebenwirkungen außerhalb der DB meldet die Wache (KiUmleitungTest/KiExternGenerateTest).',
    ];

    /**
     * Bewusst NICHT umgeleitet → Grund. Gilt, bis der Grund behoben ist.
     *
     * @var array<string, string>
     */
    public const AUSGENOMMEN = [
        'foodalchemist.concepts.GENERATE' => 'Klasse c: Kaskade, mehrere propose() mit Schreibzugriffen dazwischen (Phase 2).',
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
