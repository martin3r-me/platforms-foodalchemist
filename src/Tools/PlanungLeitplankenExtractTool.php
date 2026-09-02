<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningSession;
use Platform\FoodAlchemist\Services\BriefingLeitplankenService;

/**
 * BRIEFING → LEITPLANKEN: freier Text (getippt oder gesprochen) wird zum Regler-Satz,
 * mit dem anschliessend erzeugt wird.
 *
 * Das ist die Brücke zwischen den zwei Hälften des Systems: der suchende Teil (Briefing,
 * Sprache, Konzeptfindung) formuliert frei, der produzierende Teil (Rezept-/Gericht-
 * Generator) braucht geschlossene Vokabulare — und führt sie dann N-mal deterministisch aus.
 *
 * BEWUSST KEIN TOOL-LOOP. Eine Klassifikation gegen geschlossene Vokabulare ist keine
 * Exploration: ein Call, klein, reproduzierbar. Ein agentischer Lauf mit 2 Runden und einem
 * Tool kostet gemessen 4.687 Token und 18–21 Sekunden, und jede Runde sendet die ganze
 * Konversation neu.
 *
 * ERFUNDENE WERTE WERDEN VERWORFEN, nicht übernommen: alles läuft durch
 * PlanningSessionService::filterGenerationParams gegen
 * FoodAlchemistPlanningSession::ALLOWED_GENERATION_VALUES. Was durchfällt, kommt als
 * `verworfen` zurück — ein falscher Regler ist schlimmer als ein fehlender, weil er die
 * Erzeugung still in die falsche Richtung lenkt.
 *
 * Schreibt NUR die Regler einer Planungssitzung. Erzeugt nichts — das „Go" bleibt menschlich.
 */
class PlanungLeitplankenExtractTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.planung_leitplanken.EXTRACT';
    }

    public function getDescription(): string
    {
        return 'Destilliert aus einem freien Briefing die LEITPLANKEN (Richtungs-Regler) für die Erzeugung: '
            . 'occasion, sektor, level, serviceform, convenience, bestand, bio_praeferenz, diaet_hart, '
            . 'pax, ziel_vk_eur, ziel_portion_g, ziel_we_pct, aroma, saison. Mit session_id werden sie in die '
            . 'Planungssitzung geschrieben (nur die Regler — es wird NICHTS erzeugt, das Go bleibt menschlich); '
            . 'ohne session_id kommt ein reiner Vorschlag zurück. Werte werden gegen die geschlossenen '
            . 'Vokabulare geprüft: Erfundenes wird VERWORFEN und in `verworfen` gemeldet, nicht übernommen. '
            . 'Was das Briefing offen lässt, kommt als Rückfrage in `unklar` — es wird nicht geraten. '
            . 'Genau der Einstieg für ein gesprochenes oder getipptes Briefing.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'briefing' => [
                    'type' => 'string',
                    'description' => 'Freier Text, z. B. »Gala-Dinner für 80 Personen, gehoben, 45 € netto p. P., vegetarisch«',
                ],
                'session_id' => [
                    'type' => 'integer',
                    'description' => 'Planungssitzung, in die die Regler geschrieben werden (weglassen = nur Vorschlag)',
                ],
            ],
            'required' => ['briefing'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $briefing = trim((string) ($arguments['briefing'] ?? ''));
        if ($briefing === '') {
            return ToolResult::error('briefing ist Pflicht — ohne Text gibt es keine Leitplanken.', 'VALIDATION_ERROR');
        }

        $sessionId = isset($arguments['session_id']) ? (int) $arguments['session_id'] : null;
        if ($sessionId !== null) {
            // Ownership VOR dem LLM-Call prüfen: sonst bezahlt man einen Call für eine
            // Sitzung, in die man ohnehin nicht schreiben darf.
            $eigen = FoodAlchemistPlanningSession::query()
                ->whereKey($sessionId)->where('team_id', $team->id)->whereNull('deleted_at')->exists();
            if (! $eigen) {
                return ToolResult::error('Planungssitzung nicht gefunden oder nicht team-eigen.', 'NOT_FOUND');
            }
        }

        try {
            $r = app(BriefingLeitplankenService::class)->ausBriefing($team, $briefing, $sessionId);
        } catch (\Platform\FoodAlchemist\Exceptions\KiDeaktiviertException $e) {
            return ToolResult::error('KI ist für dieses Team deaktiviert.', 'KI_DEAKTIVIERT');
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage(), 'KI_FEHLER');
        }

        return ToolResult::success($r + [
            'hinweis' => $r['gespeichert']
                ? 'Regler in der Sitzung gesetzt. Erzeugt wurde nichts — das Go bleibt menschlich.'
                : 'Vorschlag ohne Schreiben (keine session_id übergeben).',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'command',
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'llm_call',
            'tags' => ['foodalchemist', 'planung', 'leitplanken', 'briefing', 'voice', 'ki'],
            'examples' => ['Leitplanken aus »Sommerfest für 80, Fingerfood, regional, 35 € p. P.« ableiten'],
        ];
    }
}
