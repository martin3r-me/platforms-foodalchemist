<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Ai\KiAuftragService;

/**
 * #1002: beantwortet offene KI-Aufträge. Schreibt KEINE Fachdaten — die Antwort wird nur am Auftrag gespeichert;
 * Prüfung, Guards, Übernahme und Call-Log (source=mcp, Anbieter/Modell) laufen beim Wiederholungslauf des
 * ursprünglichen Aufrufs über denselben Weg wie die Plattform-KI. Ohne gültige Auftrags-ID wird nichts gespeichert.
 */
class KiAuftraegeAntwortTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.ki_auftraege.ANTWORT';
    }

    public function getDescription(): string
    {
        return 'Beantwortet KI-Aufträge (aus foodalchemist.ki_auftraege.GET). antworten = Liste {auftrag_id, werte}; werte = das JSON '
            . 'laut Antwort-Umschlag des Auftrags. anbieter/modell = wer tatsächlich geantwortet hat (für Protokoll/Lineage). '
            . 'Je Auftrag Ergebnis ok/abgelehnt (unbekannte/fremde/abgelaufene/bereits beantwortete ID, kein JSON). '
            . 'Danach den ursprünglichen Lauf wiederholen — er übernimmt die Antworten.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'antworten' => [
                    'type' => 'array', 'maxItems' => 50,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'auftrag_id' => ['type' => 'string'],
                            'werte' => ['description' => 'Antwort-JSON laut Umschlag (Objekt) — oder dessen JSON-Text'],
                            'quellen' => ['type' => 'array', 'description' => 'NUR bei Websuche-Aufträgen (ki_auftraege.GET: web_suche=true): die tatsächlich gefundenen Seiten, je {url, title}. Ohne mindestens eine http(s)-Quelle wird die Antwort abgelehnt.',
                                'items' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string'], 'title' => ['type' => 'string']], 'required' => ['url']]],
                        ],
                        'required' => ['auftrag_id', 'werte'],
                    ],
                ],
                'anbieter' => ['type' => 'string', 'description' => 'Anbieter der Antwort, z. B. anthropic, openai'],
                'modell' => ['type' => 'string', 'description' => 'tatsächliches Modell, z. B. claude-opus-5-5'],
            ],
            'required' => ['antworten', 'anbieter', 'modell'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $anbieter = trim((string) ($arguments['anbieter'] ?? ''));
        $modell = trim((string) ($arguments['modell'] ?? ''));
        if ($anbieter === '' || $modell === '') {
            return ToolResult::error('anbieter und modell sind Pflicht (Lineage).', 'VALIDATION_ERROR');
        }
        $service = app(KiAuftragService::class);
        $ergebnis = [];
        $ok = 0;
        $angenommen = [];
        foreach ((array) ($arguments['antworten'] ?? []) as $a) {
            $id = (string) ($a['auftrag_id'] ?? '');
            try {
                $service->beantworte((int) $team->id, $id, $a['werte'] ?? '', $anbieter, $modell, isset($a['quellen']) ? (array) $a['quellen'] : null);
                $ergebnis[] = ['auftrag_id' => $id, 'status' => 'ok'];
                $angenommen[] = $id;
                $ok++;
            } catch (\Throwable $e) {
                $ergebnis[] = ['auftrag_id' => $id, 'status' => 'abgelehnt', 'grund' => $e->getMessage()];
            }
        }

        $neu = $service->stoesseWiederholungAn((int) $team->id, $angenommen);   // P2a: Job-Läufe starten sich selbst neu

        return ToolResult::success([
            'angenommen' => $ok, 'abgelehnt' => count($ergebnis) - $ok, 'ergebnisse' => $ergebnis,
            'laeufe_neu_gestartet' => $neu,
            'note' => 'Gespeichert sind nur die Antworten. Job-Läufe in „laeufe_neu_gestartet“ übernehmen sie selbst und können weitere Aufträge anlegen (ki_auftraege.GET). '
                .'Für alle anderen Aufträge (Tool-Aufruf, Backfill) den ursprünglichen Lauf wiederholen, damit Prüfung und Übernahme laufen.',
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['foodalchemist', 'ki', 'auftrag', 'extern', 'mcp'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => ['updates'],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.ki_auftraege.GET'],
            'examples' => ['Beantworte die 25 offenen gp.sensorik-Aufträge mit anthropic/claude-opus-5-5'],
        ];
    }
}
