<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\PlanningCascadeService;

/**
 * EIN Aufruf für den ganzen Planungslauf per MCP: sagt immer genau das Nächste — die nächste KI-Frage, ein Tor (Freigabe),
 * „die Plattform arbeitet“ oder „fertig“. Antworten auf die Fragen gehen mit demselben Aufruf zurück.
 *
 * Warum: auf der Plattform läuft ein Auftrag von selbst durch, weil der Job die KI direkt fragt. Per MCP legt derselbe Job die
 * Frage als KI-Auftrag ab und wartet — der Client musste dafür drei Werkzeuge im Wechsel bedienen (planung_kaskade.GET,
 * ki_auftraege.GET, ki_auftraege.ANTWORT) und selbst erkennen, worauf der Lauf gerade wartet (Lauf 62, io 13.11.2026: er blieb an
 * einer nicht abgeholten Frage stehen). Dieses Werkzeug bündelt nur — es rechnet nichts selbst: Status aus
 * {@see PlanningCascadeService::laufStatus()}, Fragen aus {@see KiAuftraegeGetTool}, Antworten über {@see KiAuftraegeAntwortTool}.
 * Die Tore bleiben Tore: freigegeben wird weiter ausdrücklich mit planung_kaskade.FREIGABE.
 */
class PlanungKaskadeWeiterTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    /** Empfohlene Pause, bevor der Client erneut fragt, solange die Plattform arbeitet. */
    public const WARTE_SEKUNDEN = 10;

    public function getName(): string
    {
        return 'foodalchemist.planung_kaskade.WEITER';
    }

    public function getDescription(): string
    {
        return 'Führt einen per MCP gestarteten Planungs-Kaskaden-Lauf Schritt für Schritt: EIN Aufruf sagt, was als Nächstes zu tun ist. '
            . 'Antwortfeld `zustand`: '
            . '`frage` = die Plattform braucht KI-Antworten (auftraege mit vollem Prompt wie ki_auftraege.GET; ein übergroßer Prompt kommt in Teilen, '
            . 'weitere Teile mit auftrag_id + teil) — beantworten und mit demselben Werkzeug zurückgeben (antworten, anbieter, modell); '
            . '`arbeitet` = die Plattform rechnet, nach warte_sekunden erneut aufrufen; '
            . '`tor` = Entwürfe oder Pläne warten auf die Freigabe (planung_kaskade.FREIGABE je step_id, danach wieder WEITER); '
            . '`fehlgeschlagen` = Schritte sind gescheitert (Grund je Schritt); `fertig` = der Lauf ist abgeschlossen. '
            . 'Einfach wiederholen, bis `fertig` kommt. Starten: planung_kaskade.START. Alles Fachliche (Prüfung der Antwort, Matching, '
            . 'Kalkulation, Speichern) macht die Plattform selbst, wie beim Lauf mit der Plattform-KI.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'run_id' => ['type' => 'integer', 'description' => 'ID des Kaskaden-Laufs (aus planung_kaskade.START).'],
                'antworten' => [
                    'type' => 'array',
                    'description' => 'optional: Antworten auf die zuletzt gelieferten Fragen, je {auftrag_id, werte} (werte = JSON laut Antwort-Umschlag der Frage). Dann sind anbieter und modell Pflicht.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'auftrag_id' => ['type' => 'string'],
                            'werte' => ['description' => 'Antwort-JSON laut Umschlag (Objekt) — oder dessen JSON-Text'],
                            'quellen' => ['type' => 'array', 'description' => 'nur bei Fragen mit web_suche=true: gefundene Seiten, je {url, title}',
                                'items' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string'], 'title' => ['type' => 'string']], 'required' => ['url']]],
                        ],
                        'required' => ['auftrag_id', 'werte'],
                    ],
                ],
                'anbieter' => ['type' => 'string', 'description' => 'Anbieter der Antworten, z. B. anthropic (Pflicht mit antworten)'],
                'modell' => ['type' => 'string', 'description' => 'tatsächliches Modell, z. B. claude-opus-5-5 (Pflicht mit antworten)'],
                'auftrag_id' => ['type' => 'string', 'description' => 'optional: nur diese Frage (für weitere Teile eines übergroßen Prompts)'],
                'teil' => ['type' => 'integer', 'description' => 'optional: Teil eines übergroßen Prompts, ab 1'],
                'max_zeichen' => ['type' => 'integer', 'description' => 'optional: Obergrenze Prompt-Text je Antwort (Standard ' . KiAuftraegeGetTool::MAX_ZEICHEN_STANDARD . ')'],
            ],
            'required' => ['run_id'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $runId = (int) ($arguments['run_id'] ?? 0);
        $cascade = app(PlanningCascadeService::class);
        if ($cascade->laufStatus($team, $runId) === null) {
            return ToolResult::error('Kaskaden-Lauf nicht gefunden oder nicht team-sichtbar.', 'NOT_FOUND');
        }

        // 1) Antworten annehmen — dieselbe Annahme wie ki_auftraege.ANTWORT (Prüfung, Lineage, Neustart der wartenden Jobs)
        $angenommen = null;
        if (! empty($arguments['antworten'])) {
            $r = app(KiAuftraegeAntwortTool::class)->execute([
                'antworten' => $arguments['antworten'], 'anbieter' => $arguments['anbieter'] ?? '', 'modell' => $arguments['modell'] ?? '',
            ], $context);
            if (! $r->success) {
                return $r;
            }
            $angenommen = array_intersect_key((array) $r->data, array_flip(['angenommen', 'abgelehnt', 'ergebnisse']));
        }

        // 2) Stand NACH der Annahme lesen (ein neu gestarteter Job kann schon die nächste Frage gestellt haben)
        $status = $cascade->laufStatus($team, $runId);
        $schritte = (array) ($status['schritte'] ?? []);
        $kopf = array_filter([
            'run_id' => $runId,
            'lauf_status' => $status['lauf']['status'] ?? null,
            'antworten' => $angenommen,
        ], static fn ($v): bool => $v !== null);
        $kurz = static fn (array $s): array => array_filter([
            'step_id' => $s['id'], 'ebene' => $s['ebene'] ?? null, 'label' => $s['label'] ?? null, 'status' => $s['status'] ?? null,
            'phase' => $s['phase'] ?? null, 'ref_type' => $s['ref_type'] ?? null, 'ref_id' => $s['ref_id'] ?? null, 'fehler' => $s['fehler'] ?? null,
        ], static fn ($v): bool => $v !== null && $v !== '');
        $gescheitert = array_values(array_map($kurz, array_filter($schritte, static fn ($s) => ($s['status'] ?? '') === 'failed')));
        $mitFehlern = static fn (array $aus): array => $gescheitert === [] ? $aus : $aus + ['fehlgeschlagene_schritte' => $gescheitert];

        // 3a) Die Plattform wartet auf Antworten → die Fragen liefern
        if (! empty($status['ki_auftraege_offen'])) {
            $fragen = app(KiAuftraegeGetTool::class)->execute(array_filter([
                'run_id' => $runId,
                'auftrag_id' => $arguments['auftrag_id'] ?? null,
                'teil' => $arguments['teil'] ?? null,
                'max_zeichen' => $arguments['max_zeichen'] ?? null,
            ], static fn ($v): bool => $v !== null && $v !== ''), $context);
            if (! $fragen->success) {
                return $fragen;
            }

            return ToolResult::success($mitFehlern($kopf + [
                'zustand' => 'frage',
                'anzahl' => $fragen->data['anzahl'],
                'weitere' => $fragen->data['weitere'],
                'auftraege' => $fragen->data['auftraege'],
                'naechster_schritt' => 'Jede Frage genau nach dem Umschlag in ihren messages beantworten (JSON) und zurückgeben: '
                    . 'planung_kaskade.WEITER(run_id=' . $runId . ', antworten=[{auftrag_id, werte}], anbieter, modell). '
                    . 'Trägt eine Frage teil/teile, erst alle Teile holen (auftrag_id + teil), dann antworten. Die Plattform prüft und verarbeitet die Antwort selbst.',
            ]));
        }

        // 3b) Die Plattform arbeitet (Job in der Warteschlange oder im Worker, auch Anreicherung/Bilder nach einer Freigabe)
        $inArbeit = array_values(array_filter($schritte, static fn ($s) => in_array($s['status'] ?? '', ['queued', 'running'], true)
            || in_array($s['anreicherung'] ?? '', ['queued', 'running'], true)
            || in_array($s['bilder'] ?? '', ['queued', 'running'], true)));
        if ($inArbeit !== []) {
            return ToolResult::success($mitFehlern($kopf + [
                'zustand' => 'arbeitet',
                'warte_sekunden' => self::WARTE_SEKUNDEN,
                'schritte' => array_map($kurz, $inArbeit),
                'naechster_schritt' => 'Die Plattform rechnet. In etwa ' . self::WARTE_SEKUNDEN . ' Sekunden planung_kaskade.WEITER(run_id=' . $runId . ') erneut aufrufen — '
                    . 'dann kommt die nächste Frage, ein Tor oder das Ergebnis.',
            ]));
        }

        // 3c) Tor: fertige Entwürfe zuerst; gibt es keine, die Pläne (ein geplanter Schritt wird durch die Freigabe erzeugt)
        $entwuerfe = array_values(array_filter($schritte, static fn ($s) => ($s['status'] ?? '') === 'done'));
        $plaene = array_values(array_filter($schritte, static fn ($s) => ($s['status'] ?? '') === 'geplant'));
        $amTor = $entwuerfe !== [] ? $entwuerfe : $plaene;
        if ($amTor !== []) {
            return ToolResult::success($mitFehlern($kopf + [
                'zustand' => 'tor',
                'tor' => $entwuerfe !== [] ? 'entwurf_freigeben' : 'plan_annehmen',
                'schritte' => array_map($kurz, $amTor),
                'naechster_schritt' => ($entwuerfe !== []
                    ? 'Entwürfe prüfen (ref_type/ref_id, z. B. recipes.GET) und je Schritt mit planung_kaskade.FREIGABE(step_id) freigeben — die Freigabe startet Anreicherung und nächste Ebene. '
                    : 'Geplante Schritte je mit planung_kaskade.FREIGABE(step_id) annehmen — die Freigabe erzeugt den Entwurf. ')
                    . 'Danach planung_kaskade.WEITER(run_id=' . $runId . '). Freigeben ist eine fachliche Entscheidung, kein Automatismus.',
            ]));
        }

        // 3d) Nichts mehr offen
        if ($gescheitert !== []) {
            return ToolResult::success($kopf + [
                'zustand' => 'fehlgeschlagen',
                'schritte' => $gescheitert,
                'naechster_schritt' => 'Gründe stehen je Schritt unter fehler. Gescheiterte Schritte setzt der Mensch im Cockpit fort (neu erzeugen oder verwerfen).',
            ]);
        }

        return ToolResult::success($kopf + [
            'zustand' => 'fertig',
            'hinweis' => $status['hinweis'] ?? null,
            'schritte' => array_map($kurz, $schritte),
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'mutation',
            'tags' => ['foodalchemist', 'planung', 'kaskade', 'leitstelle', 'weiter', 'ki', 'auftrag', 'extern', 'mcp', 'headless'],
            'read_only' => false,
            'idempotent' => false,
            'risk_level' => 'write',
            'requires_auth' => true,
            'requires_team' => true,
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.planung_kaskade.START', 'foodalchemist.planung_kaskade.FREIGABE', 'foodalchemist.planung_kaskade.GET', 'foodalchemist.ki_auftraege.GET'],
            'examples' => ['Führe Kaskaden-Lauf 62 weiter', 'Was braucht Lauf 62 als Nächstes?', 'Gib die Antworten für Lauf 62 zurück und hole die nächste Frage'],
        ];
    }
}
