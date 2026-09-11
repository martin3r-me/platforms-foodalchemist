<?php

namespace Platform\FoodAlchemist\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\KnowledgeService;

/**
 * Spec 52 Runde D — Einordnung in Blöcken.
 *
 * Der Anlass ist schlicht Menge: 1.073 aktive Dossiers brauchen Art und Geltung. Einzeln
 * über `knowledge.PUT` wären das 1.073 Aufrufe; über die Oberfläche 1.073 Klicks. Beides
 * verhindert, dass der Korpus-Umbau je fertig wird.
 *
 * ★ Kein zweiter Schreibpfad: jeder Eintrag läuft durch dasselbe
 * {@see KnowledgeService::update()} wie `knowledge.PUT`, inklusive Vokabular-Prüfung,
 * Master-Sperre und Versionierung. Ein eigener Bulk-Pfad wäre genau die Doppelung, die
 * Spec 52 abbaut — und die Stelle, an der die Prüfung eines Tages fehlt.
 *
 * ★ **Ein Fehler kippt nicht den Block.** Jede Zeile wird einzeln verbucht und einzeln
 * gemeldet. Bei 200 Zeilen ist „eine war falsch, welche?" die einzig brauchbare Antwort.
 *
 * ★ **Aktiviert wird nichts.** Freischalten bleibt eine menschliche Entscheidung; dieses
 * Werkzeug rührt `active` nicht an, auch nicht wenn jemand es mitschickt.
 */
class KnowledgeEinordnenTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    /**
     * Obergrenze je Aufruf.
     *
     * Nicht aus technischer Not, sondern als Schadensgrenze: eine falsche Zuordnung über
     * 1.000 Dossiers ist mühsam zurückzunehmen, über 200 überschaubar. Wer mehr will,
     * ruft mehrfach — und sieht zwischendurch, was passiert ist.
     */
    private const MAX = 200;

    public function getName(): string
    {
        return 'foodalchemist.knowledge.EINORDNEN';
    }

    public function getDescription(): string
    {
        return 'Ordnet MEHRERE Wissens-Dossiers in einem Aufruf ein (Art + Geltung). Fuer den '
            . 'Korpus-Umbau gedacht, wo Einzelaufrufe nicht praktikabel sind. Laeuft durch denselben '
            . 'Schreibpfad wie knowledge.PUT: Vokabular-Pruefung, Master-Sperre, Versionierung. '
            . 'Ein fehlerhafter Eintrag kippt den Block NICHT — jede Zeile wird einzeln gemeldet. '
            . 'Aktiviert nichts: Freischalten bleibt eine menschliche Entscheidung. Mit pruefen=true '
            . 'laeuft alles als Trockenlauf, ohne zu schreiben — bei grossen Bloecken zuerst so fahren.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'eintraege' => ['type' => 'array', 'description' => 'Die Dossiers, je Eintrag slug + art (+ optional geltung/datenwerte).',
                    'items' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                        'slug' => ['type' => 'string'],
                        'art' => ['type' => 'string', 'description' => 'regel | datenwerk | fachwissen | referenz | ablauf'],
                        'geltung' => ['type' => 'object', 'description' => 'Achse => Werte. Werte werden gegen das Vokabular geprueft.'],
                        'datenwerte' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Nur bei art=datenwerk.'],
                    ]]],
                'pruefen' => ['type' => 'boolean', 'default' => false,
                    'description' => 'Trockenlauf: prueft jeden Eintrag, schreibt aber nichts.'],
            ],
            'required' => ['eintraege'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }
        $eintraege = $arguments['eintraege'] ?? null;
        if (! is_array($eintraege) || $eintraege === []) {
            return ToolResult::error('Keine Eintraege uebergeben.', 'VALIDATION_ERROR');
        }
        if (count($eintraege) > self::MAX) {
            return ToolResult::error(sprintf('Hoechstens %d Eintraege je Aufruf — bei einer falschen '
                . 'Zuordnung bleibt der Schaden sonst unuebersichtlich. Bitte in Bloecken fahren.', self::MAX), 'VALIDATION_ERROR');
        }
        $trocken = ($arguments['pruefen'] ?? false) === true;

        $ok = [];
        $fehler = [];
        foreach ($eintraege as $i => $eintrag) {
            $slug = is_array($eintrag) ? trim((string) ($eintrag['slug'] ?? '')) : '';
            if ($slug === '') {
                $fehler[] = ['index' => $i, 'slug' => null, 'grund' => 'slug fehlt'];

                continue;
            }
            // `active` fliegt bewusst raus, auch wenn es jemand mitschickt.
            $daten = array_intersect_key($eintrag, array_flip(['art', 'geltung', 'datenwerte']));
            if ($daten === []) {
                $fehler[] = ['index' => $i, 'slug' => $slug, 'grund' => 'nichts einzuordnen (art/geltung/datenwerte fehlen)'];

                continue;
            }

            try {
                if ($trocken) {
                    // Dieselbe Pruefung wie beim Schreiben, nur ohne zu schreiben — und zwar
                    // WIRKLICH dieselbe. Vorher stand hier nur die Format-Pruefung: der Lauf
                    // versprach 16 Einordnungen und schrieb 12, weil er weder Existenz noch
                    // Schreibrecht ansah. Eine Zusage, die von der Tat abweicht, ist schlimmer
                    // als keine Zusage.
                    app(KnowledgeService::class)->findAenderbar($team, $slug, 'einordenbar');
                    \Platform\FoodAlchemist\Services\Knowledge\WissensGeltung::payload(
                        $daten['art'] ?? null, $daten['geltung'] ?? [], $daten['datenwerte'] ?? []);
                    $ok[] = ['slug' => $slug, 'art' => $daten['art'] ?? null, 'geschrieben' => false];

                    continue;
                }
                $doc = app(KnowledgeService::class)->update($team, $slug, $daten);
                $ok[] = ['slug' => $doc->slug, 'art' => $doc->art ?? null, 'version' => $doc->version, 'geschrieben' => true];
            } catch (\RuntimeException|\InvalidArgumentException $e) {
                $fehler[] = ['index' => $i, 'slug' => $slug, 'grund' => $e->getMessage()];
            }
        }

        return ToolResult::success([
            'modus' => $trocken ? 'trockenlauf' : 'geschrieben',
            'eingeordnet' => count($ok),
            'fehlgeschlagen' => count($fehler),
            'eintraege' => $ok,
            'fehler' => $fehler,
            'hinweis' => $fehler !== []
                ? 'Fehlerhafte Zeilen wurden ueebersprungen, der Rest ist verbucht. Die Gruende stehen je Zeile.'
                : null,
        ]);
    }

    public function getMetadata(): array
    {
        return ['category' => 'command', 'tags' => ['foodalchemist', 'wissen', 'einordnen', 'kuration', 'bulk'],
            'read_only' => false, 'idempotent' => true, 'risk_level' => 'moderate',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => ['foodalchemist.knowledge.PUT', 'foodalchemist.knowledge.LIST',
                'foodalchemist.knowledge_routings.PUT']];
    }
}
