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
    /**
     * Spec 55: der Agent lebt nur noch in der Planungs-Leitstelle — der Katalog ist auf
     * Planungsbezug geschrumpft (kein `verkaufsrezepte.SEARCH`/`artikel.SEARCH` — Lieferanten-
     * artikel/Verkaufsrezepte-Browsing gehört nicht zur Planung; kein `recipe_klasse.POST` —
     * Speisen-Klassifikation ist keine der genannten Planungs-Fähigkeiten). NEU: `formats.SEARCH`
     * + `zielgruppen.GET` + `knowledge.PREVIEW` für Wissens-fundierte Vorschläge MIT Quelle
     * (Design-Punkt d). `ui.OPEN` bleibt (öffnet Entwürfe, die eine Kaskade erzeugt hat —
     * „Schritt öffnen"), `ui.NAVIGATE`/`ui.ROUTES` bleiben technisch unverändert (der volle
     * FA-Routenkatalog ist geteilter Code, siehe `UiNavigateTool`) — die Beschränkung auf
     * planungsinterne Ziele ist eine Prompt-Regel (`system_zusatz` unten), kein Katalog-Cut.
     */
    public const TOOLS = [
        'tool_registry.SEARCH', 'tool_registry.GET',
        'foodalchemist.gps.SEARCH', 'foodalchemist.gps.GET',
        'foodalchemist.recipes.SEARCH', 'foodalchemist.recipes.GET',
        'foodalchemist.ui.OPEN',
        // Spec 53/D: ui.OPEN öffnet einen KONKRETEN Datensatz (id nötig) — für „Öffne die Planung"
        // (keine id, allgemeine Seite) gibt es NAVIGATE. Ohne den Eintrag hier kannte das Modell
        // das Werkzeug nicht (es steht zwar über die Policy offen, aber nichts im Katalog/System-
        // Prompt sagte ihm, dass es existiert) und `verarbeite()` wertete den Ruf auch nicht aus.
        'foodalchemist.ui.NAVIGATE',
        // Live-Bruch Dominique (2026-09-18): route_key trägt jetzt die kurzen Labels direkt in
        // der Schema-Beschreibung von ui.NAVIGATE (siehe UiNavigateTool) — Navigation braucht
        // dadurch normalerweise KEINEN ui.ROUTES-Aufruf mehr. Trotzdem als RÜCKFALL im Katalog
        // (winziges Schema, keine Properties — siehe Token-Deckel-Test): fehlt ein Ziel in der
        // kurzen Liste oder ändert sie sich, braucht das Modell sonst wieder einen SEARCH-Umweg.
        'foodalchemist.ui.ROUTES',
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
        // Spec 55 (Design-Punkt d): Wissens-Fundierung MIT Quelle — Formate/Zielgruppen als
        // reale Referenzen statt Bauchgefühl, `knowledge.PREVIEW` für Event-Playbooks/Regelwerke.
        'foodalchemist.formats.SEARCH',
        'foodalchemist.zielgruppen.GET',
        'foodalchemist.knowledge.PREVIEW',
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
     * Review-Fix 2026-09-17 (cooking-jarvis-03): die vier Tools aus PROPOSAL_TOOLS, die
     * tatsächlich einen Vorschlag ERZEUGEN — `planung_kaskade.LETZTE` ist reines Lesen
     * (`read_only => true`) und stand in PROPOSAL_TOOLS nur als Flag-Sicherung, nicht weil
     * es einen Vorschlag baut. Für den Modus `nur_lesen` darf NUR diese engere Liste aus
     * Katalog/Policy fliegen — sonst verliert „wie weit ist die Generierung?" grundlos
     * seine Antwort, obwohl es nichts vorschlägt und nichts schreibt.
     */
    public const SCHREIB_VORSCHLAG_TOOLS = [
        'foodalchemist.recipe_klasse.POST',
        'foodalchemist.gp_proposals.POST',
        'foodalchemist.planung_vorschlag.POST',
        'foodalchemist.anreicherung_vorschlag.POST',
    ];

    /**
     * Commit-Flags, die ein Proposal-Tool zum Direktschreiber machen. Nicht geraten,
     * sondern aus den echten Schemas erhoben: `confirm` (24 Tools), `accept` (4),
     * `apply` (3), `force` (2). Im Sprachpfad immer aus.
     */
    public const COMMIT_FLAGS = ['confirm', 'accept', 'apply', 'force'];

    /**
     * Spec 53 / Paket F: `proposals[]['type']`-Werte, die im Modus `auto_sicher` OHNE Klick
     * ausgeführt werden dürfen — weil ihre Wirkung REVERSIBEL ist (Session löschen/Klasse
     * ändern/erneut anreichern sind alle möglich). Eine explizite Liste, KEIN Namensmuster
     * (Memory: „Tool-Freigabe nach Eigenschaft, nie nach Name") — jeder Typ hier ist einzeln
     * gegen die drei erzeugenden Tools geprüft:
     *   - `planung_start` (`planung_vorschlag.POST`, read_only, schreibt selbst nichts —
     *     VoiceModal::planungStarten() legt NUR eine Planungs-Session an, keine Kaskade).
     *   - `anreicherung` (`anreicherung_vorschlag.POST`, read_only) — dispatcht einen Job,
     *     der ein bestehendes Rezept anreichert (idempotent wiederholbar).
     *   - `speisen_klasse` (`recipe_klasse.POST`) — setzt eine Klassifikation, jederzeit
     *     überschreibbar.
     * Bewusst NICHT hier: alles mit `confirm`/`accept`/`apply`/`force`-Flag im echten Schema
     * (löschen, veröffentlichen, bestellen, Status „approved" setzen) — dafür gibt es heute
     * keine Voice-Proposal-Typen, käme aber ein neuer hinzu, bräuchte er eine BEWUSSTE
     * Aufnahme hier, nicht automatisch.
     */
    public const AUTO_ERLAUBT = ['planung_start', 'anreicherung', 'speisen_klasse'];

    /**
     * Spec 53 / Paket F (1b): ECHTE Tool-Namen, die im Modus `auto_sicher` DIREKT ausgeführt
     * werden — zusätzlich zu AUTO_ERLAUBT (das sind Proposal-`type`-Werte, keine Tool-Namen).
     * Explizite Liste, keine Namensmuster: alle drei sind reversibel (Duplikat löschen,
     * Recompute ist reine Neuberechnung ohne Fachänderung, Anreicherung ist wiederholbar) und
     * tragen KEIN Commit-Flag im Schema (geprüft — `entschaerfeArgumente()` hätte sie sonst
     * wirkungslos gemacht). `recipe_klasse.POST` steht NICHT hier: es läuft schon über
     * AUTO_ERLAUBT/PROPOSAL_TOOLS (eigener, älterer Mechanismus, Aufgabe 7).
     */
    public const AUTO_SICHER_DIREKT_TOOLS = [
        'foodalchemist.recipes.DUPLICATE',
        'foodalchemist.recipes.RECOMPUTE',
        'foodalchemist.recipes.ENRICH',
    ];

    /**
     * Spec 53 / Paket F (1b): Alias-Map für die HOCHWERTIGE Schreibvorschlag-Vorschau (GET
     * vorher + Feld-Diff alt/neu). Es gibt KEINE einheitliche Tool-Form — gemessen an drei
     * PUT-Tools: RecipesPutTool nimmt `recipe_id` + flache Felder, GpsPutTool `id` + flache
     * Felder, AngebotePutTool/VerkaufsrezeptePutTool `id` + ein verschachteltes `felder`-
     * Objekt. Ein naiver Top-Level-Diff wäre für die zweite Gruppe falsch (er zeigt nur
     * `{feld:'felder', neu:{...gesamtes Objekt...}}`). Darum: nur die HIER gelisteten Tools
     * bekommen einen echten Feld-Diff, alles andere (baueSchreibvorschlag() ohne Alias-Eintrag)
     * zeigt ehrlich die rohen Argumente OHNE Alt-Wert — kein falscher Diff ist besser als ein
     * vollständiger, der stimmt zufällig nur für die Hälfte der ~330 Schreib-Tools. Erweitern,
     * sobald ein echter Sprachbefehl ein fehlendes Tool trifft; jeder Eintrag mit Kommentar,
     * woran id-Param/Wrapper-Key gemessen wurden — nicht geraten.
     */
    public const SCHREIBAKTION_ALIAS = [
        // id-Param `recipe_id`, flache Felder — gemessen an RecipesPutTool::getSchema()
        // (`'recipe_id' => [...]`, kein Wrapper-Key, Felder wie `name`/`status`/... top-level).
        'foodalchemist.recipes.PUT' => [
            'id_param' => 'recipe_id', 'get_tool' => 'foodalchemist.recipes.GET',
            'felder_key' => null, 'typ' => 'Basisrezept', 'delete' => false,
        ],
        // id-Param `id`, flache Felder — gemessen an GpsPutTool::getSchema() (`'id' => [...]`).
        'foodalchemist.gps.PUT' => [
            'id_param' => 'id', 'get_tool' => 'foodalchemist.gps.GET',
            'felder_key' => null, 'typ' => 'Grundprodukt', 'delete' => false,
        ],
        // id-Param `id` + verschachteltes `felder`-Objekt — gemessen an
        // VerkaufsrezeptePutTool::getSchema() (`'required' => ['id', 'felder']`).
        'foodalchemist.verkaufsrezepte.PUT' => [
            'id_param' => 'id', 'get_tool' => 'foodalchemist.verkaufsrezepte.GET',
            'felder_key' => 'felder', 'typ' => 'Gericht', 'delete' => false,
        ],
        // Löschen: kein Feld-Diff nötig, aber der Objekt-NAME auf der Karte ist Pflicht
        // (Review-Befund cooking-jarvis-03 — sonst bestätigt niemand sinnvoll „löschen").
        'foodalchemist.recipes.DELETE' => [
            'id_param' => 'id', 'get_tool' => 'foodalchemist.recipes.GET',
            'felder_key' => null, 'typ' => 'Basisrezept', 'delete' => true,
        ],
        'foodalchemist.gps.DELETE' => [
            'id_param' => 'id', 'get_tool' => 'foodalchemist.gps.GET',
            'felder_key' => null, 'typ' => 'Grundprodukt', 'delete' => true,
        ],
    ];

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
     * Spec 54 (2): eine zweite LLM-Runde NUR für den Schlusssatz kostet ~8-10 s Latenz für
     * nichts. Nur `ui.NAVIGATE` — bewusst NICHT `ui.OPEN`: dessen Tool-Ergebnis trägt kein
     * Label (nur `type`+`id`, siehe {@see \Platform\FoodAlchemist\Tools\UiOpenTool::execute()}),
     * ein freundlicher Satz bräuchte den echten Datensatz-Namen, den es hier nicht gibt — lieber
     * eine echte Modell-Runde als eine unfreundliche "Geöffnet: recipe #42"-Antwort. `ui.NAVIGATE`
     * hat mit `label` (aus dem Katalog, {@see \Platform\FoodAlchemist\Tools\FoodAlchemistTool::uiRouteCatalog()})
     * bereits alles, was ein kurzer, korrekter Satz braucht, und ist als reine Seiten-Navigation
     * (kein Datensatz, keine Ambiguität) IMMER eine abgeschlossene Aktion ohne offene Frage.
     */
    public static function fruehesFinale(string $name, array $arguments, \Platform\Core\Contracts\ToolResult $resultat): ?string
    {
        if ($name !== 'foodalchemist.ui.NAVIGATE' || ! $resultat->success) {
            return null;
        }
        $label = $resultat->data['navigate']['label'] ?? null;

        return is_string($label) && trim($label) !== '' ? "Öffne {$label}." : null;
    }

    /**
     * Spec 55 (Design-Punkt c): kompakter Status-Snapshot des LETZTEN Kaskaden-Laufs der aktiven
     * Planungs-Session — direkt im Systemprompt, damit der Agent proaktiv darauf antworten kann
     * („Schritt 3 ist rot — neu anstoßen?"), OHNE dass der Mensch erst danach fragt (dafür gibt es
     * bereits `foodalchemist.planung_kaskade.LETZTE` als Tool). Best effort: kein Team/keine
     * Session/kein Lauf/ein Fehler beim Lesen liefert einfach `null` — ein fehlender Hinweis ist
     * kein Grund, den Sprachbefehl abzubrechen.
     */
    private function planungsKaskadenHinweis(?int $planungsSessionId): string
    {
        if ($planungsSessionId === null) {
            return '';
        }
        $team = \Illuminate\Support\Facades\Auth::user()?->currentTeamRelation;
        if ($team === null) {
            return '';
        }
        try {
            $run = \Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun::visibleToTeam($team)
                ->where('planning_session_id', $planungsSessionId)
                ->orderByDesc('id')
                ->first();
            if ($run === null) {
                return '';
            }
            $status = app(PlanningCascadeService::class)->laufStatus($team, (int) $run->id);
        } catch (\Throwable) {
            return '';                                               // Kontext ist ein Bonus, kein Pflichtpfad
        }
        if ($status === null) {
            return '';
        }
        $zeilen = ['Status: ' . ($status['lauf']['status'] ?? 'unbekannt')];
        foreach ($status['schritte'] as $schritt) {
            if (($schritt['status'] ?? null) === 'failed') {
                $zeilen[] = 'Schritt „' . ($schritt['label'] ?? '?') . '" ist fehlgeschlagen'
                    . (($schritt['fehler'] ?? null) !== null ? ' (' . $schritt['fehler'] . ')' : '') . '.';
            }
        }

        return "\n\n[Aktuelle Kaskade dieser Planungs-Session, NUR zur Einordnung — auf einen fehlgeschlagenen "
            . "Schritt darfst du proaktiv hinweisen und einen Neustart vorschlagen, ohne dass danach gefragt wird:\n"
            . implode("\n", $zeilen) . ']';
    }

    /**
     * Spec 55 Nachtrag (Agent-am-Brief): der Lückencheck prüft ab jetzt den ECHTEN Regler-Stand
     * (`VoiceModal::$formularRegler`, per Browser-Event von `Planung\Index::render()` frisch
     * gehalten) statt nur den gesprochenen Satz zu lesen — schon gesetzte Felder werden NICHT
     * nochmal erfragt, egal ob der Mensch sie über die UI oder einen früheren Sprachbefehl
     * gesetzt hat. Nur für die drei Regler-Scopes (rezept/gericht/concept) — „Format" hat noch
     * keinen eigenen Regler-Satz, s. Spec 55 „Offene Punkte".
     *
     * @param  ?array{scope: ?string, regler: array<string,mixed>, brief: string}  $formularStand
     */
    private function formularstandHinweis(?array $formularStand): string
    {
        $scope = $formularStand['scope'] ?? null;
        if ($scope === null || ! isset(\Platform\FoodAlchemist\Livewire\Planung\Index::MANDATORY_LEITPLANKEN[$scope])) {
            return '';
        }
        $regler = is_array($formularStand['regler'] ?? null) ? $formularStand['regler'] : [];
        $brief = trim((string) ($formularStand['brief'] ?? ''));

        $gesetzt = [];
        $fehlend = [];
        foreach (\Platform\FoodAlchemist\Livewire\Planung\Index::MANDATORY_LEITPLANKEN[$scope] as $feld) {
            $wert = $regler[$feld] ?? null;
            if ($wert === null || $wert === '') {
                $fehlend[] = $feld;
            } else {
                $gesetzt[] = "{$feld}={$wert}";
            }
        }

        $zeilen = ["Scope: {$scope}"];
        $zeilen[] = $brief !== '' ? "Brief bisher: „{$brief}\"" : 'Brief bisher: (leer)';
        $zeilen[] = $gesetzt !== [] ? 'Bereits gesetzt: ' . implode(', ', $gesetzt) : 'Bereits gesetzt: nichts';
        $zeilen[] = $fehlend !== []
            ? 'FEHLENDE Pflicht-Leitplanken: ' . implode(', ', $fehlend)
            . ' — frage GEZIELT nach GENAU EINEM davon (das für den Nutzer relevanteste), NIEMALS nach '
            . 'einem bereits gesetzten Feld.'
            : 'Alle Pflicht-Leitplanken dieses Scopes sind bereits gesetzt.';

        return "\n\n[Formularstand des aktiven Planungs-Tabs — NUR hieran den Lückencheck prüfen, "
            . "NICHT am gesprochenen Satz allein:\n" . implode("\n", $zeilen) . ']';
    }

    /**
     * Spec 55 Nachtrag: das feste Vokabular EINES Regler-Feldes (für Rückfrage-Chips UND für
     * die Server-Validierung eines direkten Struktur-Werts) — `null` heisst „numerisches Feld,
     * kein festes Vokabular" (Pax/Menge/Portion/Ziel-VK), der Aufrufer erwartet dort eine Zahl.
     *
     * @return ?array<string, string>
     */
    public static function regelVokabular(string $feld): ?array
    {
        return match ($feld) {
            'sektor' => \Platform\FoodAlchemist\Livewire\Planung\Index::SEKTOR_OPTIONEN,
            'occasion' => \Platform\FoodAlchemist\Livewire\Planung\Index::OCCASION_OPTIONEN,
            'serviceform' => \Platform\FoodAlchemist\Livewire\Planung\Index::SERVICEFORM_OPTIONEN,
            'ziel_einheit' => \Platform\FoodAlchemist\Livewire\Planung\Index::MENGE_EINHEITEN,
            'level' => ['' => '(egal)', 'haute_cuisine' => 'Haute Cuisine', 'gehoben' => 'Gehoben', 'klassisch' => 'Klassisch'],
            default => null,                                          // pax/ziel_menge/ziel_portion_g/ziel_vk: Zahl, kein Enum
        };
    }

    /**
     * Spec 55 Nachtrag: Server-Validierung EINES Regler-Werts gegen sein Vokabular — die
     * Instanz, an der „unbekannter Wert → Rückfrage, nie raten" durchgesetzt wird. Enum-Felder
     * müssen ein bekannter Schlüssel sein, numerische Felder müssen numerisch sein.
     */
    public static function regelWertGueltig(string $feld, mixed $wert): bool
    {
        $vokabular = self::regelVokabular($feld);
        if ($vokabular !== null) {
            return is_string($wert) && array_key_exists($wert, $vokabular);
        }

        return is_numeric($wert) || (is_string($wert) && is_numeric(str_replace(',', '.', $wert)));
    }

    /**
     * Rundenbudget UND Zeitbudget (Befund 2026-09-17, demo-Call-Log 16.09.: 2 von 5 Läufen liefen
     * bis `maxRuns` durch — 6 Runden, ~60 s, ~91.560 Input-Token — ohne dass der Nutzer in der
     * Zeit auch nur eine Zwischenmeldung sah). 4 Runden reichen für die gemessenen Fälle (Suche,
     * Detail öffnen, Proposal) locker; das Zeitbudget ist der zweite, unabhängige Deckel, falls
     * eine einzelne Runde selbst schon lange braucht.
     */
    private const MAX_RUNDEN = 4;

    // Spec 54 (4): 28 s schnitt auf Tier D (~8-10 s/Runde) den 3.-Runden-Fall (36 s) fast
    // immer ab, BEVOR ein `final` erreicht war — reine Symptom-Verschiebung wäre ein
    // längeres Budget allein gewesen (macht die Runde nicht schneller, nur den Abbruch
    // seltener sichtbar); erst mit (1)-(3) zusammen wirkt die Anhebung sinnvoll.
    private const ZEITBUDGET_MS = 45_000;

    /**
     * @param  array{type: string, id: int}|null  $kontext  Aufgabe 7: Rezept-/Gericht-Kontext der
     *                                                        öffnenden Seite („reichere DIESES Rezept an").
     * @param  string  $modus  Spec 53/F: fragen (Default)|auto_sicher|nur_lesen — siehe
     *                          {@see \Platform\FoodAlchemist\Services\TeamSettingsService::VOICE_AGENT_MODES}.
     * @param  ?int  $planungsSessionId  Spec 55 (Design-Punkt c): die aktive Planungs-Session — trägt den
     *                                    letzten Kaskaden-Lauf (Status, roter Schritt) als Kontext, DAMIT
     *                                    der Agent proaktiv darauf antworten kann, ohne dass der Mensch
     *                                    erst fragt (z. B. „Schritt 3 ist rot — neu anstoßen?").
     * @param  ?array{scope: ?string, regler: array<string,mixed>, brief: string}  $formularStand  Spec 55
     *         Nachtrag (Agent-am-Brief): der ECHTE Regler-/Brief-Stand des aktiven Scope-Tabs
     *         (`VoiceModal::$formularRegler`/`$formularBrief`) — der Lückencheck prüft DAGEGEN,
     *         nicht nur gegen den gesprochenen Satz (schliesst die in Spec 55 dokumentierte Lücke).
     * @return array{text: ?string, unklar: bool, runden: int, elapsed_ms: int, freigeschaltet: list<string>,
     *               aktionen: list<array>, proposals: list<array>, tool_laeufe: list<array>}
     */
    public function verarbeite(
        string $transcript,
        ?array $kontext = null,
        string $modus = 'fragen',
        ?string $verlauf = null,
        ?int $planungsSessionId = null,
        ?array $formularStand = null,
    ): array {
        $kontextHinweis = ($kontext !== null && isset($kontext['type'], $kontext['id']))
            ? " [Kontext: aktuell geöffnet — {$kontext['type']} ID={$kontext['id']}. Bei \"dieses/das Rezept\" "
                . 'OHNE genannten Namen/Nummer diese ID verwenden, NICHT raten. Wird ein anderer Name genannt, '
                . 'gilt der genannte Name.]'
            : '';
        $planungsHinweis = $this->planungsKaskadenHinweis($planungsSessionId);
        $formularHinweis = $this->formularstandHinweis($formularStand);
        // Spec 53 / Paket F (4): GEKÜRZTER Gesprächsverlauf (letzte Züge + zuletzt geöffnetes
        // Objekt) — löst Pronomen/Ellipsen über den letzten Turn hinweg auf ("und jetzt lösche
        // das"). Referenz-Bestätigungen ("ja", "das zweite") laufen NICHT hier durch: die fängt
        // VoiceModal VOR diesem Aufruf ab (dieselben Methoden wie der Bestätigen-Klick).
        $verlaufHinweis = $verlauf !== null && trim($verlauf) !== ''
            ? "\n\n[Bisheriger Gesprächsverlauf dieser Sitzung, GEKÜRZT — nur zur Einordnung, keine neuen Fakten erfinden:\n{$verlauf}]"
            : '';
        // Aufgabe F: `nur_lesen` sperrt die Proposal-Tools STRUKTURELL (nicht erst am Ergebnis
        // gefiltert) — sonst würde das Modell Runden/Token für einen Vorschlag verbrauchen,
        // der ohnehin nirgends landet. `fragen`/`auto_sicher` ändern an der Tool-Policy nichts;
        // der Unterschied zwischen ihnen ist NUR, was VoiceModal mit dem Proposal danach macht.
        // Der Basiskatalog macht ein Tool sofort erlaubt, BEVOR die Policy je gefragt wird
        // (AiGatewayService::callWithTools: `$erlaubt` startet mit den übergebenen $toolNames) —
        // die Policy allein hätte `recipe_klasse.POST`/`planung_vorschlag.POST` NICHT gesperrt,
        // weil beide schon im Warmstart-Katalog stehen. Für `nur_lesen` müssen sie also aus dem
        // KATALOG raus, nicht nur aus der Policy (die bleibt als zweite Sicherung stehen, falls
        // das Modell eines trotzdem über tool_registry.SEARCH findet). NUR SCHREIB_VORSCHLAG_TOOLS
        // (nicht die ganze PROPOSAL_TOOLS-Liste) — `planung_kaskade.LETZTE` ist reines Lesen und
        // soll in nur_lesen erreichbar bleiben („wie weit ist die Generierung?" schlägt nichts vor).
        $toolsFuerModus = $modus === 'nur_lesen' ? array_values(array_diff(self::TOOLS, self::SCHREIB_VORSCHLAG_TOOLS)) : self::TOOLS;
        // Paket F (1b, Dominique: „alles was MCP-fähig ist"): in fragen/auto_sicher ist JEDES
        // foodalchemist.*-Tool AUFRUFBAR — die Grenze liegt nicht mehr an der Policy, sondern am
        // intercept()-Hook weiter unten, der JEDEN Nicht-read_only-Aufruf abfängt, bevor er
        // wirklich ausgeführt wird (Ausnahme: AUTO_SICHER_DIREKT_TOOLS in auto_sicher). Das ist
        // der einzige Punkt, an dem `read_only` geprüft wird — die Policy allein wäre hier KEINE
        // Sicherung, sie lässt den Aufruf ja bewusst durch.
        $policy = $modus === 'nur_lesen'
            ? static fn (string $name, object $tool): bool => ! in_array($name, self::SCHREIB_VORSCHLAG_TOOLS, true) && self::darfNutzen($name, $tool)
            : static fn (string $name, object $tool): bool => str_starts_with($name, 'foodalchemist.');
        $intercept = $modus === 'nur_lesen' ? null : $this->interceptor($modus);
        $modusHinweis = match ($modus) {
            'nur_lesen' => 'MODUS „nur lesen": Schreibvorschläge sind für dich komplett gesperrt (auch als '
                . 'Vorschlag). Beantworte Fragen konversationell, navigiere/öffne bei Bedarf, aber schlage NICHTS '
                . 'zum Anlegen/Anreichern/Klassifizieren vor — sag stattdessen, dass der Modus das nicht erlaubt. '
                . 'Status/letzte Läufe abfragen (foodalchemist.planung_kaskade.LETZTE) ist weiterhin erlaubt — '
                . 'das ist reines Lesen, kein Vorschlag.',
            'auto_sicher' => 'MODUS „automatisch (sicher)": deine reversiblen Vorschläge (planung_vorschlag.POST, '
                . 'anreicherung_vorschlag.POST, recipe_klasse.POST, recipes.DUPLICATE, recipes.RECOMPUTE, '
                . 'recipes.ENRICH) werden dem Nutzer NICHT zur Bestätigung vorgelegt, sondern SOFORT ausgeführt '
                . '— sag das im finalen Text auch so (z. B. „Ich habe die Planung angelegt und den Editor '
                . 'geöffnet."), nicht „bitte bestätigen". Alle ANDEREN Schreibaktionen (Rezept/GP bearbeiten '
                . 'oder löschen, usw.) bleiben trotzdem eine Karte zum Bestätigen.',
            default => 'MODUS „fragen" (Standard): jeder Vorschlag braucht einen Bestätigen-Klick vom Nutzer — '
                . 'sag das auch so (z. B. „Vorschlag: … — bitte bestätigen").',
        };
        // Paket F (1b): der generische Weg für ALLE anderen schreibenden FA-Tools (Rezept/GP
        // bearbeiten/löschen, ...) — ruf sie normal auf, das System fängt sie ab und baut eine
        // Karte; das Tool-Ergebnis bestätigt das (kein Fehler, also nicht in Runde+1 anders
        // probieren). Partial-Update-Pflicht + Mengen-Rücklese sind Prompt-Regeln (kein Code-
        // Zwang möglich, da PUT-Tools je nach Alias flach ODER verschachtelt sind).
        $schreibHinweis = 'SCHREIBAKTIONEN AUSSER DEN DREI PLANUNGS-FÄHIGKEITEN (z. B. ein Rezept bearbeiten, '
            . 'ein GP anlegen, etwas löschen): du darfst JEDES foodalchemist.*-Tool aufrufen, auch schreibende — '
            . 'so wie sie sind, nicht extra suchen ob es einen „Vorschlag"-Namen trägt. Sie werden NIE direkt '
            . 'ausgeführt (Ausnahme: die auto_sicher-Liste oben) — das System fängt sie ab und legt eine Karte '
            . 'zum Bestätigen an; das Tool-Ergebnis sagt dir „Vorschlag angelegt" — das ist ein ERFOLG, nicht '
            . 'versuche es danach nicht nochmal anders. PFLICHT bei Bearbeiten (PUT): sende NUR die im Befehl '
            . 'GENANNTEN Felder, NIE ein ganzes Objekt zurückschreiben — alles Ungenannte bleibt unangetastet. '
            . 'Nennt der Befehl eine Menge/Zahl mit Einheit (z. B. „200 Gramm Butter"), wiederhole sie im '
            . 'finalen Antworttext wörtlich, damit der Nutzer sie gegenlesen kann. ';
        // Live-Bruch Dominique (2026-09-18, Punkt d): im Konversations-Modus lief eine Endlos-
        // Schleife, weil das Modell auf ein Transkript aus Hintergrundrauschen/unklarem Gemurmel
        // trotzdem eine muntere Füllantwort gab ("Alles klar, ich warte auf deinen nächsten
        // Befehl"), die vorgelesen wurde und den nächsten automatischen Zyklus auslöste. Die
        // clientseitige VAD-Schwelle (Stufe 3B) fängt STILLE zuverlässig ab — dieser Hinweis ist
        // die zweite Sicherung für den Fall, dass ETWAS akustisch als Sprache durchkam, aber die
        // Transkription selbst kein sinnvoller Befehl ist.
        $rauschHinweis = 'WIRKT DAS TRANSKRIPT WIE RAUSCHEN/UNKLARES GEMURMEL/EIN EINZELNES UNVERSTÄNDLICHES '
            . 'WORT OHNE ERKENNBAREN BEFEHL: KEINE muntere Füllantwort ("Alles klar, ich warte …", "Wie kann '
            . 'ich helfen?" o. ä.) — antworte stattdessen NUR mit dem einen Wort „wartet" als finalen Text, '
            . 'ohne jedes Tool aufzurufen. Ein kurzer, aber ERKENNBARER Befehl (auch unvollständig) ist davon '
            . 'NICHT betroffen — im Zweifel gilt ein Transkript als Befehl, nicht als Rauschen. ';
        $resultat = $this->ki->callWithTools(
            "Sprachbefehl des Users (Deutsch, Kurz-Audio-Transkript): \"{$transcript}\"{$kontextHinweis}{$verlaufHinweis}{$planungsHinweis}{$formularHinweis}",
            $toolsFuerModus,
            self::MAX_RUNDEN,
            [
                'policy' => $policy,
                'arg_guard' => [self::class, 'entschaerfeArgumente'],
                'intercept' => $intercept,
                'zeitbudget_ms' => self::ZEITBUDGET_MS,
                'fruehes_finale' => [self::class, 'fruehesFinale'],
                // Spec 54 (1): eigene, von Tier D UNABHÄNGIGE Einstellung — `null` (Default)
                // lässt `callWithTools()`s `$optionen['model'] ?? Tier D`-Fallback greifen,
                // NICHTS ändert sich. Gesetzt übersteuert NUR den Voice-Loop, ohne die anderen
                // an Tier D hängenden Prompt-Keys (demo.echo, gp.condition, recipe.category,
                // recipe.name_putzen) mit umzustellen.
                'model' => config('foodalchemist.ai.voice_model'),
                // Spec 55: der Agent ist NICHT mehr der globale FA-Navigator (das war bis
                // Spec 53/54 der Fall) — er lebt NUR noch als Panel in der Planungs-Leitstelle.
                // Die Rolle + der Katalog (self::TOOLS) sind entsprechend geschrumpft.
                'system_zusatz' => $modusHinweis . ' ' . $schreibHinweis . $rauschHinweis
                    . 'DEINE ROLLE: Planungs-Assistent der Planungs-Leitstelle — NICHT mehr der '
                    . 'globale FoodAlchemist-Navigator. Du hilfst beim Aufbau EINER Planung (Basisrezept, '
                    . 'Gericht oder Concept): Brief formulieren, Leitplanken setzen, einen Vorschlag '
                    . 'anstossen, den Stand der laufenden Kaskade nennen. Der Katalog unten ist nur der '
                    . 'Einstieg: fehlt dir ein Werkzeug, suche es mit tool_registry.SEARCH und rufe es '
                    . 'direkt auf — Suche IMMER mit name_glob "foodalchemist.*" (Tools anderer Module '
                    . 'sind gesperrt, jede Anfrage dorthin kostet nur eine Runde). Freigeschaltet sind '
                    . 'LESENDE foodalchemist.*-Tools. Schreibende sind gesperrt; Änderungen laufen über '
                    . 'die Proposal-Tools und werden vom Menschen bestätigt. '
                    . 'Zum Öffnen eines KONKRETEN Datensatzes (z. B. ein von der Kaskade erzeugter '
                    . 'Entwurf) foodalchemist.ui.OPEN nutzen (id nötig); foodalchemist.ui.NAVIGATE NUR '
                    . 'für Ziele INNERHALB der Planung (Tabs, die eigene Session, ein Kaskaden-Schritt) — '
                    . 'die kurzen route_key-Labels stehen direkt im Schema des Tools. '
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
                    . 'LÜCKENCHECK vor (1) OHNE offene Session: nennt der Befehl NICHT mindestens Sektor, Anlass, '
                    . 'Personenzahl, Budget/Ziel-VK UND Niveau, frage GEZIELT nach den fehlenden davon, BEVOR du '
                    . 'planung_vorschlag.POST aufrufst — kein Rateversuch mit Platzhaltern. '
                    . ($planungsSessionId !== null
                        ? 'ES IST BEREITS EINE PLANUNGS-SESSION OFFEN: „erstelle eine neue Planung" bleibt (1) — '
                            . 'aber „ändere den Brief"/„setze Sektor auf …"/„Personenzahl ist 40" betrifft die '
                            . 'OFFENE Session, NICHT (1). Dafür KEIN Tool aufrufen. Den Formularstand (Regler + '
                            . 'fehlende Pflicht-Leitplanken) bekommst du unten im Kontext — DARAN prüfen, nicht '
                            . 'am gesprochenen Satz allein. Zwei Fälle: '
                            . '(A) Pflicht-Leitplanke fehlt laut Formularstand: frage GEZIELT nach GENAU EINER '
                            . '(der relevantesten), NENNE dabei im finalen Text konkrete Beispiele, UND gib '
                            . '{"rueckfrage":{"scope":"rezept|gericht|concept","feld":"<EXAKT einer der '
                            . 'gemeldeten fehlenden Schlüssel>"}} mit — der Server baut daraus die Auswahl, du '
                            . 'musst die Optionen nicht selbst aufzählen. '
                            . '(B) Der Nutzer nennt einen Wert — sei es als Antwort auf deine eigene Rückfrage '
                            . 'ODER unaufgefordert („Sektor ist jetzt Catering"): DANN gib '
                            . '{"struktur":{"scope":"...","felder":{"<Schlüssel>":"<Wert>"},"direkt":true}} mit '
                            . '— die Antwort auf eine konkrete Frage IST die Bestätigung, kein Klick nötig. '
                            . 'Schreibe NUR Werte, die du aus dem Gesagten sicher ableiten kannst (z. B. „gehoben" '
                            . '→ level=gehoben, „60 Personen" → pax=60); bist du unsicher ob der Wert zum '
                            . 'Vokabular passt, frage lieber nochmal (Fall A) statt zu raten — ein Wert ausserhalb '
                            . 'des Vokabulars wird ohnehin serverseitig verworfen. '
                            . 'NUR bei einem LÄNGEREN, unaufgeforderten Briefing-Text (mehrere Leitplanken auf '
                            . 'einmal, keine Antwort auf eine eigene Frage) — {"struktur":{...}} OHNE "direkt" '
                            . '(oder "direkt":false): das bleibt eine Karte zum Bestätigen (GL-07), der Mensch '
                            . 'soll mehrere gleichzeitig vorgeschlagene Werte vor der Übernahme sehen. '
                            . 'Leitplanken-Schlüssel EXAKT: sektor, occasion (Anlass), pax (Personen), ziel_vk '
                            . '(Budget), level (Niveau), serviceform, ziel_menge, ziel_einheit, ziel_portion_g — '
                            . 'NUR die tatsächlich genannten/erfragten Felder, nichts erfinden. '
                        : '')
                    . 'WISSENS-FUNDIERUNG: für Sektor/Anlass-Empfehlungen ZUERST foodalchemist.formats.SEARCH '
                    . '(passende Formate), foodalchemist.zielgruppen.GET (hinterlegte Segmente) oder '
                    . 'foodalchemist.knowledge.PREVIEW (Event-Playbooks/Regelwerke) prüfen — im finalen Text '
                    . 'die Quelle nennen („laut Format …", „Event-Playbook …"), NICHT raten, wenn nichts '
                    . 'passt sag das statt zu erfinden. '
                    . 'ARBEITSWEISE für ALLES ANDERE: geht es um eine Fach-Aufgabe (Rezept, Gericht, Concept) '
                    . 'AUSSER den drei Planungs-Fähigkeiten oben, hole ZUERST den hinterlegten '
                    . 'Ablauf mit foodalchemist.ablauf.GET — dort stehen die verbindlichen Regeln und die '
                    . 'Reihenfolge; für (1)-(3) ist das NICHT nötig, sie sind schon vollständig beschrieben. '
                    . 'Nicht aus dem Gedächtnis arbeiten und keine Werte erfinden: fehlt etwas, ist die '
                    . 'Lücke die Antwort.',
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
            // Paket F (1b): jeder vom intercept()-Hook abgefangene Schreibversuch trägt
            // `data.schreibaktion` — generischer Proposal-Typ für die ~330 FA-Write-Tools ohne
            // eigenes Proposal-Tool. `success` ist hier immer true (baueSchreibvorschlag() liefert
            // ToolResult::success), das Unterscheidungsmerkmal ist der `schreibaktion`-Schlüssel.
            if (isset($lauf['data']['schreibaktion'])) {
                $proposals[] = ['type' => 'schreibaktion'] + $lauf['data']['schreibaktion'];
            }
        }
        // Spec 55 (Design-Punkt b): das Modell darf im finalen JSON zusätzlich ein "struktur"-Feld
        // mitgeben (additive Protokoll-Erweiterung, s. AiGatewayService::callWithTools()) — Feld-
        // Vorschläge für die OFFENE Planungs-Session (Regler/Brief), OHNE eigenes MCP-Tool.
        // Whitelist gegen AGENT_SCHREIBBARE_REGLER: das Modell darf NICHTS erfinden, was
        // Planung\Index::regler() beim Übernehmen nicht kennt (kein Katalog-Wachstum, keine
        // ungeprüften Keys in einer fremden Komponente).
        //
        // Spec 55 Nachtrag (Agent-am-Brief, Dominique-Präzisierung): antwortet der Nutzer auf
        // eine Rückfrage des Agenten, ist die Antwort SELBST die Bestätigung — kein Umweg über
        // eine Übernehmen-Karte. `direkt: true` markiert genau diesen Fall; JEDER Feldwert wird
        // trotzdem serverseitig gegen sein Vokabular geprüft (regelWertGueltig()) — ein Wert
        // ausserhalb des Vokabulars wird NICHT übernommen (weder direkt noch als Vorschlag),
        // „nie raten" gilt für BEIDE Wege gleich.
        $struktur = $resultat['struktur'] ?? null;
        if (is_array($struktur) && in_array($struktur['scope'] ?? null, ['rezept', 'gericht', 'concept'], true)) {
            $rohFelder = array_intersect_key(
                is_array($struktur['felder'] ?? null) ? $struktur['felder'] : [],
                array_flip(\Platform\FoodAlchemist\Livewire\Planung\Index::AGENT_SCHREIBBARE_REGLER),
            );
            $direkt = ($struktur['direkt'] ?? false) === true;
            $felder = $direkt
                ? array_filter($rohFelder, static fn ($wert, $feld) => self::regelWertGueltig($feld, $wert), ARRAY_FILTER_USE_BOTH)
                : $rohFelder;
            $brief = is_string($struktur['brief'] ?? null) ? trim($struktur['brief']) : null;
            if ($felder !== [] || ($brief !== null && $brief !== '')) {
                $proposals[] = [
                    'type' => $direkt ? 'komponenten_direkt' : 'komponenten_uebernahme',
                    'scope' => $struktur['scope'],
                    'felder' => $felder,
                    'brief' => $brief !== '' ? $brief : null,
                ];
            }
        }

        // Spec 55 Nachtrag: "rueckfrage" — der Agent fragt GEZIELT nach EINEM fehlenden Feld.
        // Das VOKABULAR kommt ausschliesslich vom Server (regelVokabular()), NIE vom Modell —
        // der Katalog/die Chips sind damit unabhängig davon, ob das Modell die Optionen korrekt
        // aufzählt. `null`-Vokabular (Pax/Menge/Portion/Ziel-VK) heisst „Zahlenfeld", das Panel
        // zeigt dafür ein Eingabefeld statt Chips.
        $rueckfrage = $resultat['rueckfrage'] ?? null;
        if (is_array($rueckfrage)
            && in_array($rueckfrage['scope'] ?? null, ['rezept', 'gericht', 'concept'], true)
            && is_string($rueckfrage['feld'] ?? null)
            && in_array($rueckfrage['feld'], \Platform\FoodAlchemist\Livewire\Planung\Index::AGENT_SCHREIBBARE_REGLER, true)
        ) {
            $proposals[] = [
                'type' => 'rueckfrage',
                'scope' => $rueckfrage['scope'],
                'feld' => $rueckfrage['feld'],
                'vokabular' => self::regelVokabular($rueckfrage['feld']),
            ];
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

    /**
     * Paket F (1b): der `intercept`-Hook für {@see \Platform\FoodAlchemist\Services\Ai\AiGatewayService::callWithTools()}.
     * `null` = normal ausführen (Lesen, eigene Proposal-Tools, explizit freigegebene
     * auto_sicher-Direkt-Tools); sonst wird die Ausführung durch einen Schreibvorschlag ERSETZT.
     */
    private function interceptor(string $modus): callable
    {
        return function (string $name, array $arguments, object $tool, \Platform\Core\Contracts\ToolContext $context) use ($modus) {
            // Live-Bruch Dominique (2026-09-18): OHNE diese Modul-Grenze fängt der Interceptor
            // JEDEN Aufruf ab, auch `tool_registry.SEARCH/GET` (Core, kein FA-Tool) — die haben
            // kein FA-Metadatum, also ist `read_only` dort NIE `true`, und der Agent verlor die
            // Fähigkeit, überhaupt ein Werkzeug zu FINDEN (jede Suche wurde zur sinnlosen
            // Schreib-Karte „SEARCH: tool_registry.SEARCH — bitte bestätigen"). Die Policy
            // (`str_starts_with($name,'foodalchemist.')`) erlaubt Registry/Core-Tools schon
            // regulär — der Interceptor darf sie NICHT zusätzlich abfangen.
            if (! str_starts_with($name, 'foodalchemist.')) {
                return null;
            }
            $meta = method_exists($tool, 'getMetadata') ? (array) $tool->getMetadata() : [];
            if (($meta['read_only'] ?? null) === true) {
                return null;                                            // liest nur — normal ausführen
            }
            if (in_array($name, self::PROPOSAL_TOOLS, true)) {
                return null;                                            // eigene Proposal-Tools schreiben strukturell nichts
            }
            if ($modus === 'auto_sicher' && in_array($name, self::AUTO_SICHER_DIREKT_TOOLS, true)) {
                return null;                                            // explizit freigegeben — direkt ausführen
            }

            return $this->baueSchreibvorschlag($name, $arguments, $context);
        };
    }

    /**
     * Generischer Schreibvorschlag: GET-Vorher (nur mit Alias-Eintrag, siehe SCHREIBAKTION_ALIAS)
     * + Feld-Diff, sonst ehrliche Roh-Argumente ohne Alt-Wert + Tool-Beschreibung als Kontext.
     * Liefert IMMER `ToolResult::success()` — ein Fehler würde das Modell zu einem anderen
     * Versuch in der nächsten Runde verleiten, obwohl der Vorschlag schon steht.
     */
    private function baueSchreibvorschlag(string $name, array $arguments, \Platform\Core\Contracts\ToolContext $context): \Platform\Core\Contracts\ToolResult
    {
        $registry = app(\Platform\Core\Tools\ToolRegistry::class);
        $alias = self::SCHREIBAKTION_ALIAS[$name] ?? null;
        $objekt = ['type' => null, 'id' => null, 'name' => null];
        $vorschau = [];
        $beschreibung = null;

        if ($alias !== null) {
            $id = $arguments[$alias['id_param']] ?? null;
            $objekt['type'] = $alias['typ'];
            $objekt['id'] = $id;
            $vorher = null;
            $getTool = $id !== null ? $registry->get($alias['get_tool']) : null;
            if ($getTool !== null) {
                $r = $getTool->execute(['id' => (int) $id], $context);
                $vorher = $r->success ? $r->data : null;
            }
            $objekt['name'] = $vorher['name'] ?? null;
            if (! ($alias['delete'] ?? false)) {
                $felder = $alias['felder_key'] !== null ? (array) ($arguments[$alias['felder_key']] ?? []) : $arguments;
                foreach ($felder as $feld => $neu) {
                    if ($feld === $alias['id_param']) {
                        continue;
                    }
                    $alt = is_array($vorher) ? ($vorher[$feld] ?? null) : null;
                    if ($alt !== $neu) {
                        $vorschau[] = ['feld' => $feld, 'alt' => $alt, 'neu' => $neu];
                    }
                }
            }
        } else {
            // Kein Alias: ehrlich ohne Alt-Wert (kein falscher Diff) + die Tool-Beschreibung
            // (existiert schon, kostet nichts) fürs Verständnis, WAS das Tool tut.
            $tool = $registry->get($name);
            $beschreibung = $tool !== null
                ? mb_strimwidth(explode('.', $tool->getDescription())[0] ?? '', 0, 200, '…')
                : null;
            foreach ($arguments as $feld => $neu) {
                $vorschau[] = ['feld' => $feld, 'neu' => $neu];
            }
            foreach (['id', 'recipe_id', 'gp_id'] as $key) {              // best-effort NUR für die Anzeige
                if (isset($arguments[$key])) {
                    $objekt['id'] = $arguments[$key];

                    break;
                }
            }
            $teile = explode('.', $name);
            $objekt['type'] = $teile[1] ?? null;
        }

        return \Platform\Core\Contracts\ToolResult::success([
            'schreibaktion' => [
                'tool' => $name, 'arguments' => $arguments, 'objekt' => $objekt,
                'vorschau' => $vorschau, 'beschreibung' => $beschreibung,
            ],
            '_hinweis' => 'Vorschlag angelegt, wartet auf Bestätigung des Nutzers — nicht erneut versuchen.',
        ]);
    }
}
