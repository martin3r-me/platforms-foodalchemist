<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;

/**
 * BRIEFING → LEITPLANKEN — die Brücke zwischen dem suchenden und dem produzierenden Teil.
 *
 * Zielbild (Dominique): „Ich gebe der KI ein Briefing und die Leitplanken und sie baut ein
 * vernünftiges Rezept." Genau hier entsteht der zweite Teil dieses Satzes aus dem ersten:
 * freier Text (getippt oder gesprochen) wird zum strukturierten Regler-Satz, den der
 * deterministische Generator anschliessend N-mal ausführt.
 *
 * WARUM KEIN TOOL-LOOP. Das ist eine Klassifikation gegen geschlossene Vokabulare, keine
 * Exploration. Gemessen am 2026-09-02: ein agentischer `voice.command`-Lauf mit 2 Runden
 * und EINEM Tool kostet 4.687 Token und 18–21 Sekunden; jede Runde sendet die ganze
 * Konversation neu. Für »welcher der sechs Anlässe ist das« ist ein Call richtig — klein,
 * schnell, reproduzierbar. Der Tool-Loop verdient seinen Platz dort, wo das Modell wirklich
 * suchen muss (»welche unserer Konzepte passen?«), nicht hier.
 *
 * DIE LEITPLANKE GEGEN HALLUZINATION ist die Wert-Prüfung, nicht der Prompt. Ein erfundener
 * Wert (»Gala« statt `dinner`) liefe sonst stumm durch und ins Leere — das Achsen-Mapping
 * löst `occasion`/`sektor` deterministisch auf und findet für Unbekanntes nichts. Deshalb
 * geht alles durch {@see PlanningSessionService::filterGenerationParams}, und was dort
 * durchfällt, wird dem Menschen GEMELDET statt verschwiegen.
 *
 * Der Mensch behält die Entscheidung: geschrieben wird in die Planungssitzung (Entwurf),
 * erzeugt wird nichts. Das „Go" bleibt menschlich.
 */
class BriefingLeitplankenService
{
    public function __construct(
        private AiGatewayService $ki,
        private PlanningSessionService $sessions,
    ) {
    }

    /**
     * Leitplanken aus einem Briefing destillieren.
     *
     * @param  int|null  $sessionId  gesetzt = die Sitzung wird aktualisiert (nur Regler,
     *                               nichts erzeugt); null = reiner Vorschlag ohne Schreiben
     * @param  string|null  $scope  Was entsteht (rezept|gericht|concept). Null = scope-neutral
     *                              (MCP-Altpfad). Gesetzt: Prompt + Nachbearbeitung kennen den Tab.
     * @return array{leitplanken: array<string, mixed>, verworfen: list<string>,
     *               unklar: list<string>, begruendung: ?string, gespeichert: bool,
     *               confidence: float, call_log_id: int|null, scope_fremd: list<string>}
     */
    public function ausBriefing(Team $team, string $briefing, ?int $sessionId = null, ?string $scope = null): array
    {
        $briefing = trim($briefing);
        if ($briefing === '') {
            throw new \InvalidArgumentException('Briefing ist leer — ohne Text gibt es keine Leitplanken.');
        }

        $vorschlag = $this->ki->propose('planung.leitplanken', [
            'briefing' => $briefing,
            // Der Regler-Satz kommt aus dem Model, damit Prompt und Prüfung nicht auseinanderlaufen.
            'erlaubte_regler' => FoodAlchemistPlanningSession::ALLOWED_GENERATION_PARAMS,
            // Ohne den Tab fragte die Ableitung im Basisrezept-Tab nach »Gramm pro Person«
            // (demo Session #138) — ein Halbfabrikat hat einen Ansatz, keinen Teller.
            ...(isset(self::ERSTELLT_WIRD[$scope]) ? ['erstellt_wird' => self::ERSTELLT_WIRD[$scope]] : []),
        ], [
            'target_table' => 'foodalchemist_planning_sessions',
            'target_id' => $sessionId,
            // Ohne Regler UND ohne Suchbegriffe ist die Antwort wertlos → Gateway re-rollt statt Leeres zu
            // liefern. Ein Briefing ohne Regler-Signal kann trotzdem gute Suchbegriffe tragen (Spec 80).
            'structural_retry' => fn (array $p) => (is_array($p['werte']['leitplanken'] ?? null) && $p['werte']['leitplanken'] !== [])
                || (is_array($p['werte']['suchbegriffe'] ?? null) && $p['werte']['suchbegriffe'] !== []),
        ]);

        $roh = is_array($vorschlag->werte['leitplanken'] ?? null) ? $vorschlag->werte['leitplanken'] : [];
        $verworfen = [];
        $leitplanken = $this->sessions->filterGenerationParams($roh, $verworfen) ?? [];
        // Spec 80 C4: „keine Diät" ist eine Aussage, kein fehlender Wert. Die KI liefert dann `diaet_hart: []`;
        // der Filter wirft leere Listen weg, und ein vorher gesetztes „vegetarisch" blieb stehen (demo #138).
        // Für die Regler zählt das ausdrückliche Leer — persistiert wird es nicht (setGenerationParams filtert).
        if (array_key_exists('diaet_hart', $roh) && $roh['diaet_hart'] === []) {
            $leitplanken['diaet_hart'] = [];
        }
        $scopeFremd = [];
        $leitplanken = self::aufScopeZuschneiden($leitplanken, $scope, $scopeFremd);

        $unklar = array_values(array_filter(array_map(
            static fn ($u) => is_scalar($u) ? trim((string) $u) : '',
            (array) ($vorschlag->werte['unklar'] ?? []),
        ), static fn (string $u): bool => $u !== ''));

        $gespeichert = false;
        if ($sessionId !== null && $leitplanken !== []) {
            // Nur die Regler der Sitzung — die Erzeugung löst ein Mensch aus.
            $this->sessions->setGenerationParams($team, $sessionId, $leitplanken);
            $gespeichert = true;
        }

        return [
            'leitplanken' => $leitplanken,
            'verworfen' => $verworfen,
            'unklar' => $unklar,
            'begruendung' => is_string($vorschlag->werte['begruendung'] ?? null)
                ? trim($vorschlag->werte['begruendung'])
                : null,
            'gespeichert' => $gespeichert,
            'confidence' => $vorschlag->confidence,
            'call_log_id' => $vorschlag->callLogId,
            'scope_fremd' => $scopeFremd,
            'suchbegriffe' => self::suchbegriffeNormalisieren($vorschlag->werte['suchbegriffe'] ?? null),
        ];
    }

    /** Je Gruppe höchstens so viele Begriffe — mehr verwässert die Suche. */
    private const SUCHBEGRIFFE_JE_GRUPPE = 8;

    /**
     * Spec 80 A1/A2: KI-Antwort `{zutaten:[…], komponenten:[…], techniken:[…], aromen:[…]}` → flache Liste
     * `[{t, g, q:'ki'}]`. Fail-soft gegen jede Form: unbekannte Gruppen fallen weg, Zahlen/Mengen
     * („80g", „2 kg") und Ein-/Zwei-Zeichen-Reste fliegen raus, Dubletten (ohne Groß/klein) einmal.
     *
     * @return list<array{t: string, g: string, q: string}>
     */
    public static function suchbegriffeNormalisieren(mixed $roh): array
    {
        if (! is_array($roh)) {
            return [];
        }
        $out = [];
        $gesehen = [];
        foreach (FoodAlchemistPlanningSession::SUCHBEGRIFF_GRUPPEN as $gruppe) {
            $n = 0;
            foreach ((array) ($roh[$gruppe] ?? []) as $begriff) {
                if (! is_scalar($begriff)) {
                    continue;
                }
                $t = trim(preg_replace('/\s+/u', ' ', (string) $begriff) ?? '');
                $schluessel = mb_strtolower($t);
                if (mb_strlen($t) < 3 || mb_strlen($t) > 60 || isset($gesehen[$schluessel])
                    || preg_match('/^\d+([.,]\d+)?\s*(g|kg|ml|l|stk|pax|%)?$/iu', $t) === 1) {
                    continue;
                }
                $gesehen[$schluessel] = true;
                $out[] = ['t' => $t, 'g' => $gruppe, 'q' => 'ki'];
                if (++$n >= self::SUCHBEGRIFFE_JE_GRUPPE) {
                    break;
                }
            }
        }

        return $out;
    }

    /** Prompt-Hinweis je Tab: was entsteht und welche Mengen-Achse gilt. */
    private const ERSTELLT_WIRD = [
        'rezept' => 'BASISREZEPT (Halbfabrikat/Komponente, z. B. Sauce, Püree, Fond — kein Teller). '
            . 'Mengen-Achse ist der ANSATZ (Produktionsmenge, z. B. 1,6 kg Püree): ziel_menge + ziel_einheit '
            . '(l|ml|kg|g|stk). Portionsgrößen entscheidet erst das Gericht — pax, ziel_portion_g, occasion, '
            . 'serviceform, ziel_vk_eur NICHT setzen und NICHT nach Portionen fragen. '
            . 'Nennt das Briefing Personen × Gramm, rechne den Ansatz aus (100 Pax à 80 g ⇒ ziel_menge=8, ziel_einheit=kg). '
            . 'Fehlt die Menge, frage in `unklar` nach dem Ansatz (»Wie groß soll der Ansatz sein?«).',
        'gericht' => 'GERICHT (Teller/Portion für Gäste). Mengen-Achse: pax + ziel_portion_g. '
            . 'ziel_menge/ziel_einheit NICHT setzen.',
        'concept' => 'CONCEPT (ganzes Menü/Buffet aus mehreren Gängen). Mengen-Achse: pax. '
            . 'ziel_portion_g, ziel_menge, ziel_einheit NICHT setzen.',
    ];

    /** Felder, die im jeweiligen Tab keinen Regler haben — sie liefen sonst unsichtbar in den Lauf. */
    private const SCOPE_FREMD = [
        'rezept' => ['pax', 'ziel_portion_g', 'occasion', 'serviceform', 'ziel_vk_eur'],
        'gericht' => ['ziel_menge', 'ziel_einheit'],
        'concept' => ['ziel_portion_g', 'ziel_menge', 'ziel_einheit'],
    ];

    /**
     * Deterministische Nacharbeit nach dem Prompt: Basisrezept rechnet Pax × Portion in einen Ansatz um
     * (falls die KI das nicht selbst tat), danach fliegen die scope-fremden Felder raus — gemeldet, nicht
     * verschwiegen. Ohne Scope unverändert (MCP-Altpfad).
     *
     * @param  array<string, mixed>  $leitplanken
     * @param  list<string>  $scopeFremd  Out: entfernte Feldnamen
     * @return array<string, mixed>
     */
    public static function aufScopeZuschneiden(array $leitplanken, ?string $scope, ?array &$scopeFremd = null): array
    {
        $scopeFremd = [];
        if (! isset(self::SCOPE_FREMD[$scope])) {
            return $leitplanken;
        }
        if ($scope === 'rezept' && ! isset($leitplanken['ziel_menge'])
            && is_numeric($leitplanken['pax'] ?? null) && is_numeric($leitplanken['ziel_portion_g'] ?? null)) {
            $gramm = (float) $leitplanken['pax'] * (float) $leitplanken['ziel_portion_g'];
            if ($gramm > 0) {
                $leitplanken['ziel_menge'] = $gramm >= 1000 ? round($gramm / 1000, 3) : round($gramm, 1);
                $leitplanken['ziel_einheit'] = $gramm >= 1000 ? 'kg' : 'g';
            }
        }
        foreach (self::SCOPE_FREMD[$scope] as $feld) {
            if (array_key_exists($feld, $leitplanken)) {
                $scopeFremd[] = $feld;
                unset($leitplanken[$feld]);
            }
        }

        return $leitplanken;
    }
}
