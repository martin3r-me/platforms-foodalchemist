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
        // Spec 53/D: ui.OPEN öffnet einen KONKRETEN Datensatz (id nötig) — für „Öffne die Planung"
        // (keine id, allgemeine Seite) gibt es NAVIGATE. Ohne den Eintrag hier kannte das Modell
        // das Werkzeug nicht (es steht zwar über die Policy offen, aber nichts im Katalog/System-
        // Prompt sagte ihm, dass es existiert) und `verarbeite()` wertete den Ruf auch nicht aus.
        'foodalchemist.ui.NAVIGATE',
        // Ohne das hier handelt der Sprach-Agent aus dem Bauch. Erlaubt war es ueber die Policy
        // schon immer (jedes lesende foodalchemist.*-Tool ist es) — aber nichts im Katalog und
        // nichts in der System-Nachricht sagte ihm, dass es ein Wissensmodul gibt, und ein
        // Werkzeug, von dem das Modell nichts weiss, existiert fuer es nicht.
        //
        // NUR `ablauf.GET`, bewusst: gemessen kostet es 783 Zeichen, `knowledge.SEARCH` 1.341 —
        // zusammen reissen sie den Token-Deckel (8.490 statt 8.000), und der Katalog wird in
        // JEDER Runde bezahlt. `ablauf.GET` ist der Einstieg, der die Arbeit macht (Ablauf +
        // verbindliche Regeln in einem Zug); die Einzelfrage danach ist die Ausnahme. Ihr
        // Werkzeug steht namentlich in der System-Nachricht und wird ueber tool_registry.SEARCH
        // geholt — ein Name in Prosa kostet 30 Zeichen statt 1.341.
        'foodalchemist.ablauf.GET',
        // Review-Befund 2026-09-17 (cooking-jarvis-03): mit MAX_RUNDEN=4 und der „hole ZUERST
        // ablauf.GET"-Anweisung frisst ein tool_registry.SEARCH-Umweg für diese drei eine ganze
        // Runde — „Erstelle ein Gericht …" läge dann exakt am Limit (Ablauf, Search, Tool, final).
        // Klein genug fürs Direkt-Aufrufen (kleine Schemas, siehe Token-Deckel-Test).
        'foodalchemist.planung_vorschlag.POST',
        'foodalchemist.anreicherung_vorschlag.POST',
        'foodalchemist.planung_kaskade.LETZTE',
    ];

    /**
     * Tools, die auch OHNE (oder trotz künftig geändertem) `read_only`-Flag erreichbar bleiben
     * SOLLEN, weil ihre Wirkung für den Sprachpfad sicher ist:
     *   - `recipe_klasse.POST` ohne Commit-Flag = Klassen-Vorschlag (Bestätigen in der UI).
     *   - `gp_proposals.POST` = Beschaffungs-Wunsch im Sourcing-Backlog, laut eigenem
     *     Docblock ausdrücklich „KEIN GP-Write".
     *   - `planung_vorschlag.POST` / `anreicherung_vorschlag.POST` (Aufgabe 7, GL-07): schreiben
     *     nichts (siehe deren eigene Docblocks), tragen aber „POST" im Namen — die Doppelsicherung
     *     ist Absicht (Review-Befund 2026-09-17): ändert jemand künftig ihr `read_only`-Flag aus
     *     Versehen, bleiben sie über DIESE Liste trotzdem als Vorschlag erreichbar statt komplett
     *     zu verschwinden.
     *   - `planung_kaskade.LETZTE` ist ohnehin nur Lesen (kein POST/PUT), steht hier aus demselben
     *     Vorsichtsgrund.
     * Bewusst NICHT hier: `match_proposals.PUT` (das ist das Übernehmen, nicht der Vorschlag).
     */
    public const PROPOSAL_TOOLS = [
        'foodalchemist.recipe_klasse.POST',
        'foodalchemist.gp_proposals.POST',
        'foodalchemist.planung_vorschlag.POST',
        'foodalchemist.anreicherung_vorschlag.POST',
        'foodalchemist.planung_kaskade.LETZTE',
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
     * Rundenbudget UND Zeitbudget (Befund 2026-09-17, demo-Call-Log 16.09.: 2 von 5 Läufen liefen
     * bis `maxRuns` durch — 6 Runden, ~60 s, ~91.560 Input-Token — ohne dass der Nutzer in der
     * Zeit auch nur eine Zwischenmeldung sah). 4 Runden reichen für die gemessenen Fälle (Suche,
     * Detail öffnen, Proposal) locker; das Zeitbudget ist der zweite, unabhängige Deckel, falls
     * eine einzelne Runde selbst schon lange braucht.
     */
    private const MAX_RUNDEN = 4;

    private const ZEITBUDGET_MS = 28_000;

    /**
     * @param  array{type: string, id: int}|null  $kontext  Aufgabe 7: Rezept-/Gericht-Kontext der
     *                                                        öffnenden Seite („reichere DIESES Rezept an").
     * @return array{text: ?string, unklar: bool, runden: int, elapsed_ms: int, freigeschaltet: list<string>,
     *               aktionen: list<array>, proposals: list<array>, tool_laeufe: list<array>}
     */
    public function verarbeite(string $transcript, ?array $kontext = null): array
    {
        $kontextHinweis = ($kontext !== null && isset($kontext['type'], $kontext['id']))
            ? " [Kontext: aktuell geöffnet — {$kontext['type']} ID={$kontext['id']}. Bei \"dieses/das Rezept\" "
                . 'OHNE genannten Namen/Nummer diese ID verwenden, NICHT raten. Wird ein anderer Name genannt, '
                . 'gilt der genannte Name.]'
            : '';
        $resultat = $this->ki->callWithTools(
            "Sprachbefehl des Users (Deutsch, Kurz-Audio-Transkript): \"{$transcript}\"{$kontextHinweis}",
            self::TOOLS,
            self::MAX_RUNDEN,
            [
                'policy' => [self::class, 'darfNutzen'],
                'arg_guard' => [self::class, 'entschaerfeArgumente'],
                'zeitbudget_ms' => self::ZEITBUDGET_MS,
                'system_zusatz' => 'Du steuerst den GANZEN FoodAlchemist (Rezepte, Gerichte, Concepter, Foodbook, '
                    . 'Speisekarte, Speiseplan, Bestellwesen, Lieferanten). Der Katalog unten ist nur der Einstieg: '
                    . 'fehlt dir ein Werkzeug, suche es mit tool_registry.SEARCH und rufe es direkt auf. '
                    . 'Suche IMMER mit name_glob "foodalchemist.*" (z. B. {"query":"foodbook kapitel",'
                    . '"name_glob":"foodalchemist.*"}) — Tools anderer Module sind gesperrt, jede Anfrage dorthin '
                    . 'kostet nur eine Runde. Freigeschaltet sind LESENDE foodalchemist.*-Tools. Schreibende sind '
                    . 'gesperrt; Änderungen laufen über die Proposal-Tools und werden vom Menschen bestätigt. '
                    . 'Zum Öffnen eines KONKRETEN Datensatzes foodalchemist.ui.OPEN nutzen (id nötig), '
                    . 'zum Wechseln auf eine allgemeine Seite ohne Datensatz (z. B. „Öffne die Planung") '
                    . 'foodalchemist.ui.NAVIGATE mit route_key aus foodalchemist.ui.ROUTES. '
                    . 'DREI PLANUNGS-FÄHIGKEITEN — direkt aufrufen, KEIN vorheriges tool_registry.SEARCH nötig '
                    . '(stehen schon im Katalog oben): '
                    . '(1) foodalchemist.planung_vorschlag.POST für „erstelle/baue ein Rezept/Gericht/Menü …" — '
                    . 'legt NICHTS an, nur einen Vorschlag zum Bestätigen; '
                    . '(2) foodalchemist.anreicherung_vorschlag.POST für „reichere dieses Rezept an" — ebenfalls '
                    . 'nur ein Vorschlag; '
                    . '(3) foodalchemist.planung_kaskade.LETZTE für „wie weit ist die Generierung?" (liest die '
                    . 'letzten Läufe, keine run_id nötig). '
                    . 'foodalchemist.planung_session.POST und foodalchemist.planung_kaskade.START sind für dich '
                    . 'GESPERRT (echte Schreiber) — NIE versuchen, IMMER stattdessen (1)/(2) vorschlagen. '
                    . 'ARBEITSWEISE für ALLES ANDERE: geht es um eine Fach-Aufgabe (Rezept, Gericht, Konzept, '
                    . 'Foodbook, GP) AUSSER den drei Planungs-Fähigkeiten oben, hole ZUERST den hinterlegten '
                    . 'Ablauf mit foodalchemist.ablauf.GET — dort stehen die verbindlichen Regeln und die '
                    . 'Reihenfolge; für (1)-(3) ist das NICHT nötig, sie sind schon vollständig beschrieben. '
                    . 'Für eine einzelne Fachfrage hole dir foodalchemist.knowledge.SEARCH über '
                    . 'tool_registry.SEARCH. Nicht aus dem Gedächtnis arbeiten und keine Werte erfinden: '
                    . 'fehlt etwas, ist die Lücke die Antwort.',
            ],
        );

        $aktionen = [];
        $proposals = [];
        foreach ($resultat['tool_laeufe'] as $lauf) {
            if ($lauf['name'] === 'foodalchemist.ui.OPEN' && $lauf['success']) {
                $aktionen[] = $lauf['data']['open'];
            }
            // Spec 53/D: NAVIGATE lieferte bisher zwar ein Tool-Ergebnis, aber verarbeite() wertete
            // es nie aus — der Agent konnte die Seite wechseln, ohne dass am Modal je etwas ankam.
            if ($lauf['name'] === 'foodalchemist.ui.NAVIGATE' && $lauf['success']) {
                $aktionen[] = ['type' => 'navigate'] + $lauf['data']['navigate'];
            }
            if ($lauf['name'] === 'foodalchemist.recipe_klasse.POST' && $lauf['success'] && ! ($lauf['data']['accepted'] ?? false)) {
                $proposals[] = ['type' => 'speisen_klasse', 'recipe_id' => $lauf['arguments']['recipe_id'] ?? null] + $lauf['data'];
            }
            // Aufgabe 7 (GL-07): „erstelle ein …" darf nur bis zum Vorschlag kommen — der Knopf im
            // Modal (VoiceModal::planungStarten()) legt die Session erst beim Bestätigen an.
            if ($lauf['name'] === 'foodalchemist.planung_vorschlag.POST' && $lauf['success']) {
                $proposals[] = ['type' => 'planung_start'] + $lauf['data']['vorschlag'];
            }
            if ($lauf['name'] === 'foodalchemist.anreicherung_vorschlag.POST' && $lauf['success']) {
                $proposals[] = ['type' => 'anreicherung'] + $lauf['data']['vorschlag'];
            }
        }

        // Befund 2026-09-17: `text === null` (Runden-/Zeitbudget erschöpft, kein `final`) rendert
        // vorher NICHTS als Antwort — die graue Meta-Zeile („N Runde(n) · N Tool-Aufruf(e)") stand
        // allein da, für den Nutzer nach bis zu einer Minute Stille „nichts ist passiert". Ein Satz,
        // der die versuchten Werkzeuge nennt, ist ehrlicher als eine leere Ergebnisbox.
        $unklar = $resultat['text'] === null;
        $resultat['text'] = $unklar ? $this->unklarText($resultat['tool_laeufe']) : $resultat['text'];

        return $resultat + ['unklar' => $unklar, 'aktionen' => $aktionen, 'proposals' => $proposals];
    }

    private function unklarText(array $toolLaeufe): string
    {
        $versucht = array_values(array_unique(array_column($toolLaeufe, 'name')));
        if ($versucht === []) {
            return 'Ich habe den Befehl nicht verstanden — bitte anders formulieren.';
        }

        return 'Kein passendes Werkzeug gefunden (versucht: ' . implode(', ', $versucht)
            . ') — bitte den Befehl präziser formulieren.';
    }
}
