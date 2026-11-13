<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Ai\KiAuftragService;

/**
 * #1002: offene KI-Aufträge des externen Wegs — voller Prompt (Messages inkl. Wissens-Kanon, Kontext, Antwort-Umschlag),
 * den der MCP-Client mit seinem Modell beantwortet. Antwort per foodalchemist.ki_auftraege.ANTWORT.
 */
class KiAuftraegeGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    /** Obergrenze Prompt-Text je Antwort: unter der Ergebnisgröße üblicher MCP-Clients (≈ 25.000 Tokens), mit Luft für Umschlag und Kopf. */
    public const MAX_ZEICHEN_STANDARD = 40000;

    public const MAX_ZEICHEN_MIN = 5000;

    public const MAX_ZEICHEN_MAX = 400000;

    public function getName(): string
    {
        return 'foodalchemist.ki_auftraege.GET';
    }

    public function getDescription(): string
    {
        return 'Listet OFFENE KI-Aufträge (externer Anbieter-Weg) mit vollem Prompt (messages) und Antwort-Vorgabe. '
            . 'Beantworte jeden Auftrag exakt nach dem Umschlag in den messages (JSON) und sende ihn per foodalchemist.ki_auftraege.ANTWORT zurück. '
            . 'Aufträge entstehen, wenn ein KI-Lauf im externen Modus (per MCP gestartete Kaskade, Backfill --anbieter=extern) keinen Plattform-Provider ruft. '
            . 'Das Paket ist auf max_zeichen Prompt-Text begrenzt (Standard ' . self::MAX_ZEICHEN_STANDARD . '): es kommen so viele Aufträge, wie hineinpassen, '
            . '`weitere` nennt den Rest (einfach erneut aufrufen, nachdem das Paket beantwortet ist). Ein einzelner Auftrag, der größer ist '
            . '(z. B. recipe.generator mit Regelwerk und Bestand), kommt in Teilen: `teil`/`teile` stehen am Auftrag, die übrigen Teile mit '
            . 'auftrag_id + teil holen und je Nachricht (nr) in Reihenfolge aneinanderhängen — erst mit ALLEN Teilen antworten. '
            . 'run_id = nur die Aufträge eines Planungs-Kaskaden-Laufs (planung_kaskade.GET nennt sie unter ki_auftraege_offen). '
            . 'nur_kopf = Übersicht ohne Prompt (ID, Feature, Größe, Lauf).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'description' => 'Paketgröße, 1–50 (Standard 50); zusätzlich begrenzt max_zeichen das Paket'],
                'feature' => ['type' => 'string', 'description' => 'optional: nur dieses Feature (Prompt-Key, z. B. gp.sensorik)'],
                'run_id' => ['type' => 'integer', 'description' => 'optional: nur die offenen Aufträge dieses Planungs-Kaskaden-Laufs'],
                'auftrag_id' => ['type' => 'string', 'description' => 'optional: genau dieser Auftrag (für Einzelabruf und weitere Teile)'],
                'teil' => ['type' => 'integer', 'description' => 'optional: Teil eines übergroßen Auftrags, ab 1 (Standard 1)'],
                'max_zeichen' => ['type' => 'integer', 'description' => 'optional: Obergrenze Prompt-Text je Antwort, ' . self::MAX_ZEICHEN_MIN . '–' . self::MAX_ZEICHEN_MAX . ' (Standard ' . self::MAX_ZEICHEN_STANDARD . ')'],
                'nur_kopf' => ['type' => 'boolean', 'description' => 'optional: nur die Übersicht (ohne messages)'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $service = app(KiAuftragService::class);
        $feature = isset($arguments['feature']) && $arguments['feature'] !== '' ? (string) $arguments['feature'] : null;
        $nurKopf = (bool) ($arguments['nur_kopf'] ?? false);
        $teil = max(1, (int) ($arguments['teil'] ?? 1));
        $max = max(self::MAX_ZEICHEN_MIN, min(self::MAX_ZEICHEN_MAX,
            (int) ($arguments['max_zeichen'] ?? config('foodalchemist.ai.extern_get_max_zeichen', self::MAX_ZEICHEN_STANDARD))));

        // Filter auf einen Lauf bzw. einen einzelnen Auftrag: beides wird zu einer ID-Liste
        $uuids = null;
        if (! empty($arguments['run_id'])) {
            $uuids = app(\Platform\FoodAlchemist\Services\PlanningCascadeService::class)->kiAuftragIdsFuerLauf($team, (int) $arguments['run_id']);
            if ($uuids === null) {
                return ToolResult::error('Kaskaden-Lauf nicht gefunden oder nicht team-sichtbar.', 'NOT_FOUND');
            }
        }
        $auftragId = trim((string) ($arguments['auftrag_id'] ?? ''));
        if ($auftragId !== '') {
            $uuids = $uuids === null ? [$auftragId] : array_values(array_intersect($uuids, [$auftragId]));
        }

        $zeilen = $service->offene((int) $team->id, (int) ($arguments['limit'] ?? 50), $feature, $uuids);
        $gesamt = $service->offenAnzahl((int) $team->id, $feature, $uuids);
        if ($auftragId !== '' && $zeilen === []) {
            return ToolResult::error("KI-Auftrag {$auftragId} ist nicht (mehr) offen oder gehört nicht zu diesem Filter.", 'NOT_FOUND');
        }

        $aus = [];
        $verbraucht = 0;
        foreach ($zeilen as $z) {
            $messages = (array) json_decode((string) $z->messages, true);
            $zeichen = self::zeichen($messages);
            $kopf = [
                'auftrag_id' => $z->uuid,
                'feature' => $z->feature,
                'ziel' => $z->target_table ? ['tabelle' => $z->target_table, 'id' => $z->target_id] : null,
                'laeuft_ab_at' => $z->laeuft_ab_at,
                'zeichen' => $zeichen,
            ];
            if (! empty($z->lauf_key)) {
                $kopf['lauf'] = (string) $z->lauf_key;   // Job-Lauf, der auf diese Antwort wartet und mit ihr neu startet
            }
            // 2d-2: Websuche-Auftrag — selbst im Web suchen und die gefundenen Seiten als quellen mitsenden (sonst abgelehnt)
            $webSuche = (json_decode((string) ($z->optionen ?? ''), true)['include_web_search'] ?? false) === true;
            if ($nurKopf) {
                $aus[] = $kopf + ['teile' => max(1, (int) ceil($zeichen / $max)), 'web_suche' => $webSuche];

                continue;
            }
            if ($zeichen > $max) {
                // Übergroß (Lauf 62, io 13.11.2026: recipe.generator mit ~120.000 Zeichen sprengte die Ergebnisgröße des MCP-Clients — der
                // Auftrag war nicht lesbar, der Lauf hing): nie zusammen mit anderen, sondern allein und in Teilen.
                if ($aus !== []) {
                    break;                                           // kommt als erster Auftrag des nächsten Pakets
                }
                $teile = self::inTeilen($messages, $max);
                if ($teil > count($teile)) {
                    return ToolResult::error("Auftrag {$z->uuid} hat nur " . count($teile) . ' Teile.', 'VALIDATION_ERROR');
                }
                $aus[] = $kopf + [
                    'teil' => $teil, 'teile' => count($teile),
                    'messages' => $teile[$teil - 1],
                    'web_suche' => $webSuche,
                    'hinweis' => 'Prompt in ' . count($teile) . ' Teilen. Die übrigen mit ki_auftraege.GET(auftrag_id="' . $z->uuid . '", teil=N) holen; '
                        . 'Stücke mit gleicher nr gehören zu einer Nachricht (in Reihenfolge aneinanderhängen). Erst mit allen Teilen antworten.',
                ];

                break;
            }
            if ($aus !== [] && $verbraucht + $zeichen > $max) {
                break;
            }
            $verbraucht += $zeichen;
            $aus[] = $kopf + ['messages' => $messages, 'web_suche' => $webSuche];
        }

        $weitere = max(0, $gesamt - count($aus));

        return ToolResult::success([
            'anzahl' => count($aus),
            'offen_gesamt' => $gesamt,
            'weitere' => $weitere,
            'auftraege' => $aus,
            'note' => 'Je Auftrag: Antwort als JSON-Umschlag laut messages an foodalchemist.ki_auftraege.ANTWORT (auftrag_id, werte, anbieter, modell).'
                . ($weitere > 0 && ! $nurKopf ? " Noch {$weitere} weitere offen: nach dem Beantworten erneut aufrufen." : ''),
        ]);
    }

    /** @param array<int, mixed> $messages */
    private static function zeichen(array $messages): int
    {
        $n = 0;
        foreach ($messages as $m) {
            $n += mb_strlen(self::text($m['content'] ?? ''));
        }

        return $n;
    }

    private static function text(mixed $content): string
    {
        return is_string($content) ? $content : (string) json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Zerlegt den Prompt in Teile zu höchstens $max Zeichen. Jede Nachricht behält ihre Nummer (nr, ab 1) und Rolle; eine lange Nachricht
     * läuft über mehrere Stücke (fortsetzung = schließt an das vorige Stück derselben nr an). Der Text bleibt zeichengenau erhalten.
     *
     * @param  array<int, mixed>  $messages
     * @return list<list<array{nr: int, role: string, content: string, fortsetzung: bool}>>
     */
    private static function inTeilen(array $messages, int $max): array
    {
        $teile = [];
        $aktuell = [];
        $frei = $max;
        foreach (array_values($messages) as $i => $m) {
            $text = self::text($m['content'] ?? '');
            $laenge = mb_strlen($text);
            $pos = 0;
            do {
                if ($frei <= 0) {
                    $teile[] = $aktuell;
                    $aktuell = [];
                    $frei = $max;
                }
                $stueck = mb_substr($text, $pos, $frei);
                $aktuell[] = ['nr' => $i + 1, 'role' => (string) ($m['role'] ?? 'user'), 'content' => $stueck, 'fortsetzung' => $pos > 0];
                $pos += mb_strlen($stueck);
                $frei -= mb_strlen($stueck);
            } while ($pos < $laenge);
        }
        if ($aktuell !== []) {
            $teile[] = $aktuell;
        }

        return $teile;
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'read',
            'tags' => ['foodalchemist', 'ki', 'auftrag', 'extern', 'mcp'],
            'read_only' => true,
            'idempotent' => true,
            'risk_level' => 'safe',
            'requires_auth' => true,
            'requires_team' => true,
            'side_effects' => [],
            'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.ki_auftraege.ANTWORT', 'foodalchemist.planung_kaskade.GET'],
            'examples' => ['Hole die offenen KI-Aufträge und beantworte sie', 'Hole die offenen KI-Aufträge von Kaskaden-Lauf 62', 'Hole Teil 2 von Auftrag 01a1…'],
        ];
    }
}
