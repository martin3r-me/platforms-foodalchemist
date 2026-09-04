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
     * @return array{leitplanken: array<string, mixed>, verworfen: list<string>,
     *               unklar: list<string>, begruendung: ?string, gespeichert: bool,
     *               confidence: float, call_log_id: int|null}
     */
    public function ausBriefing(Team $team, string $briefing, ?int $sessionId = null): array
    {
        $briefing = trim($briefing);
        if ($briefing === '') {
            throw new \InvalidArgumentException('Briefing ist leer — ohne Text gibt es keine Leitplanken.');
        }

        $vorschlag = $this->ki->propose('planung.leitplanken', [
            'briefing' => $briefing,
            // Der Regler-Satz kommt aus dem Model, damit Prompt und Prüfung nicht auseinanderlaufen.
            'erlaubte_regler' => FoodAlchemistPlanningSession::ALLOWED_GENERATION_PARAMS,
        ], [
            'target_table' => 'foodalchemist_planning_sessions',
            'target_id' => $sessionId,
            // Ohne Regler ist die Antwort wertlos → Gateway re-rollt statt Leeres zu liefern.
            'structural_retry' => fn (array $p) => is_array($p['werte']['leitplanken'] ?? null)
                && $p['werte']['leitplanken'] !== [],
        ]);

        $roh = is_array($vorschlag->werte['leitplanken'] ?? null) ? $vorschlag->werte['leitplanken'] : [];
        $verworfen = [];
        $leitplanken = $this->sessions->filterGenerationParams($roh, $verworfen) ?? [];

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
        ];
    }
}
