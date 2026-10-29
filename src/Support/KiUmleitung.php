<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Ai\KiAuftragService;
use Platform\FoodAlchemist\Services\Ai\KiExternModus;

/**
 * #1002 · Umleitung: ein MCP-Aufruf eines KI-Tools läuft extern. Das Tool bekommt den KI-Auftrag statt eines OpenAI-Calls,
 * der Client beantwortet ihn (ki_auftraege.GET → ANTWORT) und wiederholt den Aufruf.
 *
 * Der Haken sitzt in {@see \Platform\FoodAlchemist\Tools\FaRechteToolHuelle} (wickelt jedes FA-Tool). Nur Tools in {@see TOOLS}
 * werden umgeleitet — jedes dort mit Begründung und eigenem Test (tests/Feature/KiUmleitungTest.php). Der Lauf steht in einer
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
        'foodalchemist.gp_forms.ESTIMATE' => 'Ein propose() (gp.zaehl_einheiten), danach nur DB-Schreiben der Formen.',
        'foodalchemist.presentation_designs.GENERATE_CSS' => 'Ein propose() (praesentation.design_css), liefert nur den Vorschlag, schreibt nicht.',
        'foodalchemist.recipe_coherence.POST' => 'Ein propose() je Modus (vk.kohaerenz / vk.teller_heber), danach DB-Schreiben der Bewertung.',
        'foodalchemist.recipe_klasse.POST' => 'Ein propose() (vk.speisen_klasse), optional DB-Übernahme (accept). Das Tool fängt Throwable: darum meldet die Hülle über den Modus, nicht über die Ausnahme.',
        'foodalchemist.recipe_rollen.POST' => 'Ein propose() (vk.rollen), optional DB-Übernahme (accept).',
        'foodalchemist.recipe_sensorik.POST' => 'Ein propose() (recipe.sensorik), danach DB-Schreiben des Profils.',
    ];

    /**
     * Bewusst NICHT umgeleitet → Grund. Gilt, bis der Grund behoben ist.
     *
     * @var array<string, string>
     */
    public const AUSGENOMMEN = [
        'foodalchemist.concepts.GENERATE' => 'Klasse c: Kaskade, mehrere propose() mit Schreibzugriffen dazwischen (Phase 2).',
        'foodalchemist.gps.ENRICH' => 'Queue-Dispatch: BulkEnrichService::starteGp legt einen Lauf an, propose() läuft im Worker außerhalb des Tool-Aufrufs. Braucht ein Extern-Flag am Lauf (Klasse c, Phase 1).',
        'foodalchemist.recipes.ENRICH' => 'Queue-Dispatch wie gps.ENRICH (Phase 2).',
        'foodalchemist.recipes.GENERATE' => 'Klasse c: Generator-Kaskade (Phase 2).',
    ];

    public static function umleiten(string $toolName): bool
    {
        return isset(self::TOOLS[$toolName]) && (bool) config('foodalchemist.ai.mcp_extern', true);
    }

    /** @param callable(): ToolResult $lauf */
    public static function ausfuehren(callable $lauf): ToolResult
    {
        $db = DB::connection();
        $modus = app(KiExternModus::class);
        $niveau = $db->transactionLevel();
        $db->beginTransaction();
        $ergebnis = null;
        $fehler = null;
        try {
            $ergebnis = $modus->withinSammelnd($lauf);
        } catch (\Throwable $e) {
            $fehler = $e;
        }
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
