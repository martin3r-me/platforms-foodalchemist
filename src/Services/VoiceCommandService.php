<?php

namespace Platform\FoodAlchemist\Services;

use Platform\FoodAlchemist\Services\Ai\AiGatewayService;

/**
 * M7-10 / Phase C2: Sprachbefehl → agentischer Tool-Loop (Tier D).
 *
 * Das Mikrofon ist die MCP-Achse in der Oberfläche: derselbe Tool-Layer, den ein
 * externer MCP-Client fährt, nur mit dem angemeldeten Nutzer als Kontext.
 *
 * WARUM KEINE GROSSE WHITELIST. Live gemessen (demo, 429 FA-Tools): die Schemas aller
 * 111 lesenden FA-Tools sind 78.348 Zeichen ≈ 26.000 Token — und der Katalog steckt bei
 * `callWithTools` als JSON in der System-Message, wird also in JEDER Runde neu bezahlt.
 * Eine „alles Lesende"-Liste würde das Token-Problem nur verschieben. Discovery allein
 * genügt aber auch nicht: ein per `tool_registry.SEARCH` gefundenes Tool stand nicht in
 * der Whitelist und war damit nicht ausführbar.
 *
 * DARUM: eine POLICY statt einer Liste. Der Basiskatalog bleibt klein (Warmstart für die
 * häufigen Fälle + die zwei Discovery-Tools); fordert das Modell etwas anderes an,
 * entscheidet `darfNutzen()` — FA-Namespace UND `read_only === true`, oder explizit ein
 * Proposal-Tool. Die Grenze ist eine EIGENSCHAFT des Tools, keine Liste, die veraltet:
 * ein morgen hinzukommendes lesendes Tool ist sofort erreichbar, ein schreibendes
 * strukturell nicht. Fail-closed — fehlt das Flag, gilt „nein".
 *
 * GL-07 bleibt die Invariante: Schreibaktionen laufen ausschliesslich über die
 * Proposal-Tools, sprechen → Proposal → bestätigen. Zwei Fallen sind dabei ECHT und
 * hier ausdrücklich geschlossen:
 *   1. `match_proposals.PUT` trägt „proposals" im Namen, ÜBERNIMMT aber einen Match
 *      (accept/reject) — ein echter Schreiber. Er ist KEIN Proposal-Tool. Ein Filter
 *      nach Namensmuster hätte dem Sprach-Agenten erlaubt, LA→GP-Mappings selbst
 *      festzuschreiben.
 *   2. `recipe_klasse.POST` ist ein Proposal-Tool, schreibt aber bei `accept: true`.
 *      Deshalb entschärft `entschaerfeArgumente()` die Commit-Flags — ohne das wäre die
 *      Invariante nur ein Docblock.
 */
class VoiceCommandService
{
    /**
     * Warmstart: die häufigen Lesewege + die zwei Discovery-Tools. Alles Weitere holt
     * sich der Loop über die Policy — das kostet keine Prompt-Zeichen, weil der Katalog
     * in der System-Message der Basiskatalog bleibt (byte-stabil, W3-1).
     *
     * Live gemessen: 6.366 Zeichen ≈ 2.122 Token je Runde (statt 26.000).
     */
    public const TOOLS = [
        'tool_registry.SEARCH', 'tool_registry.GET',
        'foodalchemist.gps.SEARCH', 'foodalchemist.gps.GET',
        'foodalchemist.recipes.SEARCH', 'foodalchemist.recipes.GET',
        'foodalchemist.verkaufsrezepte.SEARCH', 'foodalchemist.artikel.SEARCH',
        'foodalchemist.recipe_klasse.POST',
        'foodalchemist.ui.OPEN',
    ];

    /**
     * Schreibende Tools, die trotzdem erlaubt sind, WEIL ihre Wirkung ein Vorschlag ist:
     *   - `recipe_klasse.POST` ohne Commit-Flag = Klassen-Vorschlag (Bestätigen in der UI).
     *   - `gp_proposals.POST` = Beschaffungs-Wunsch im Sourcing-Backlog, laut eigenem
     *     Docblock ausdrücklich „KEIN GP-Write".
     * Bewusst NICHT hier: `match_proposals.PUT` (das ist das Übernehmen, nicht der Vorschlag).
     */
    public const PROPOSAL_TOOLS = [
        'foodalchemist.recipe_klasse.POST',
        'foodalchemist.gp_proposals.POST',
    ];

    /**
     * Commit-Flags, die ein Proposal-Tool zum Direktschreiber machen. Nicht geraten,
     * sondern aus den echten Schemas erhoben: `confirm` (24 Tools), `accept` (4),
     * `apply` (3), `force` (2). Im Sprachpfad immer aus.
     */
    public const COMMIT_FLAGS = ['confirm', 'accept', 'apply', 'force'];

    public function __construct(private AiGatewayService $ki)
    {
    }

    /**
     * Darf der Sprach-Loop dieses Tool ausführen? Fail-closed.
     *
     * `read_only === true` STRIKT: ein Tool ohne das Flag (oder mit null) gilt als
     * schreibend. Heute tragen alle 429 FA-Tools es gesetzt — die Strenge schützt
     * gegen das eine, das es morgen vergisst.
     */
    public static function darfNutzen(string $name, object $tool): bool
    {
        if (! str_starts_with($name, 'foodalchemist.')) {
            return false;                                            // Modul-Grenze
        }
        if (in_array($name, self::PROPOSAL_TOOLS, true)) {
            return true;
        }
        $meta = method_exists($tool, 'getMetadata') ? (array) $tool->getMetadata() : [];

        return ($meta['read_only'] ?? null) === true;
    }

    /** GL-07 hart: kein Commit-Flag überlebt den Sprachpfad. */
    public static function entschaerfeArgumente(string $name, array $arguments): array
    {
        foreach (self::COMMIT_FLAGS as $flag) {
            if (array_key_exists($flag, $arguments)) {
                $arguments[$flag] = false;
            }
        }

        return $arguments;
    }

    /**
     * @return array{text: ?string, runden: int, elapsed_ms: int, freigeschaltet: list<string>,
     *               aktionen: list<array>, proposals: list<array>, tool_laeufe: list<array>}
     */
    public function verarbeite(string $transcript): array
    {
        $resultat = $this->ki->callWithTools(
            "Sprachbefehl des Users (Deutsch, Kurz-Audio-Transkript): \"{$transcript}\"",
            self::TOOLS,
            6,
            [
                'policy' => [self::class, 'darfNutzen'],
                'arg_guard' => [self::class, 'entschaerfeArgumente'],
                'system_zusatz' => 'Du steuerst den GANZEN FoodAlchemist (Rezepte, Gerichte, Concepter, Foodbook, '
                    . 'Speisekarte, Speiseplan, Bestellwesen, Lieferanten). Der Katalog unten ist nur der Einstieg: '
                    . 'fehlt dir ein Werkzeug, suche es mit tool_registry.SEARCH und rufe es direkt auf. '
                    . 'Suche IMMER mit name_glob "foodalchemist.*" (z. B. {"query":"foodbook kapitel",'
                    . '"name_glob":"foodalchemist.*"}) — Tools anderer Module sind gesperrt, jede Anfrage dorthin '
                    . 'kostet nur eine Runde. Freigeschaltet sind LESENDE foodalchemist.*-Tools. Schreibende sind '
                    . 'gesperrt; Änderungen laufen über die Proposal-Tools und werden vom Menschen bestätigt. '
                    . 'Zum Navigieren foodalchemist.ui.OPEN nutzen.',
            ],
        );

        $aktionen = [];
        $proposals = [];
        foreach ($resultat['tool_laeufe'] as $lauf) {
            if ($lauf['name'] === 'foodalchemist.ui.OPEN' && $lauf['success']) {
                $aktionen[] = $lauf['data']['open'];
            }
            if ($lauf['name'] === 'foodalchemist.recipe_klasse.POST' && $lauf['success'] && ! ($lauf['data']['accepted'] ?? false)) {
                $proposals[] = ['type' => 'speisen_klasse', 'recipe_id' => $lauf['arguments']['recipe_id'] ?? null] + $lauf['data'];
            }
        }

        return $resultat + ['aktionen' => $aktionen, 'proposals' => $proposals];
    }
}
