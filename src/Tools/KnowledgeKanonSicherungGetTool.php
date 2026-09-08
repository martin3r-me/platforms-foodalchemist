<?php

namespace Platform\FoodAlchemist\Tools;

use InvalidArgumentException;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\FoodAlchemist\Services\Knowledge\KanonSicherungService;

/**
 * Spec 52 · Paket 3 (MCP-Fläche, Grundsatz E) — ist die Kanon-Kuration gesichert?
 *
 * Der Kanon lebt nur als Zeilen in der Live-DB (`H7`). Auf demo gibt es keine Shell, das
 * Kommando ist dort also nicht erreichbar — dieses Tool ist der einzige Weg, die Frage zu
 * stellen, bevor sie sich bei einem Neuaufbau von selbst beantwortet.
 *
 * Read-only und mit Absicht: Schreiben (`export`/`import`) fasst Dateien in einem
 * Git-Checkout an. Über MCP geschrieben landete die Datei auf dem Server statt im Repo —
 * also genau nicht dort, wo der Wiederherstellungs-Pfad sie braucht.
 */
class KnowledgeKanonSicherungGetTool extends FoodAlchemistTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'foodalchemist.knowledge_kanon_sicherung.GET';
    }

    public function getDescription(): string
    {
        return 'Prüft, ob die kuratierte Kanon-Verdrahtung gesichert ist: hält den Live-Kanon gegen '
            . 'die Sicherungsdatei im Repo (database/kanon/kanon-team-<id>.json) und meldet vier '
            . 'Dinge getrennt — Zeilen NUR live (kuratiert, aber nicht gesichert: bei einem Neuaufbau '
            . 'verloren), Zeilen NUR in der Datei (live entfernt), abweichende Werte (mode/ord/active) '
            . 'und Slugs OHNE Dossier (die Sicherung käme nur halb zurück). Ohne Datei liefert es den '
            . 'zu sichernden Stand. Der Kanon existiert sonst ausschliesslich als DB-Zeilen — ohne ihn '
            . 'laufen die Generatoren still ohne Regelwerk. Read-only.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'mit_zeilen' => ['type' => 'boolean', 'description' => 'optional: den vollständigen zu sichernden Stand mitliefern (Default false — bei 28 Zeilen sonst viel Text)'],
                'datei' => ['type' => 'string', 'description' => 'optional: abweichender Pfad zur Sicherungsdatei'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $team = $this->team($context);
        if ($team === null) {
            return ToolResult::error('Kein Team im Kontext.', 'NO_TEAM');
        }

        $dienst = app(KanonSicherungService::class);
        $datei = trim((string) ($arguments['datei'] ?? '')) ?: $dienst->standardDatei((int) $team->id);
        $zeilen = $dienst->zeilen($team);

        $antwort = ['datei' => $datei, 'live_zeilen' => count($zeilen)];
        if ((bool) ($arguments['mit_zeilen'] ?? false)) {
            $antwort['zeilen'] = $zeilen;
        }

        if (! is_file($datei)) {
            // Kein Fehler, sondern der Befund selbst — und der häufigste: es gibt noch keine.
            return ToolResult::success($antwort + [
                'gesichert' => false,
                'hinweis' => $zeilen === []
                    ? 'Weder Sicherung noch Kanon. Bei einem Generator-Lauf fällt der Gateway auf die '
                        .'alten Bindungen zurück — gibt es die auch nicht, läuft er ohne Regelwerk, ohne Fehler.'
                    : count($zeilen).' kuratierte Kanon-Zeilen sind NICHT gesichert. Sie existieren nur in '
                        .'dieser DB. `php artisan foodalchemist:wissen-kanon-sicherung export --team='
                        .$team->id.'` schreibt sie ins Repo.',
            ]);
        }

        try {
            $abgleich = $dienst->abgleich($team, $dienst->lade($datei));
        } catch (InvalidArgumentException $e) {
            return ToolResult::error($e->getMessage(), 'VALIDATION_ERROR');
        }

        // Zwei Zustände, die man nicht verwechseln darf: „deckungsgleich" heisst, die Datei bildet
        // den heutigen Stand ab. Ob sie sich auch EINSPIELEN liesse, sagt erst `ohne_dossier` —
        // eine perfekt deckungsgleiche Sicherung ist wertlos, wenn ihre Slugs neu geschnitten wurden.
        $hinweis = match (true) {
            $abgleich['ohne_dossier'] !== [] => count($abgleich['ohne_dossier']).' Slug(s) der Sicherung '
                .'existieren hier nicht. Diese Verdrahtungen kämen bei einem Neuaufbau NICHT zurück — '
                .'nach einem Korpus-Umbau der Normalfall: neu exportieren, sobald die Nachfolger stehen.',
            ! $abgleich['deckungsgleich'] => 'Sicherung und Live-Stand laufen auseinander — '
                .count($abgleich['nur_live']).' nur live, '.count($abgleich['nur_datei']).' nur in der Datei, '
                .count($abgleich['abweichend']).' mit anderen Werten.',
            default => null,
        };

        return ToolResult::success($antwort + $abgleich + ['gesichert' => true, 'hinweis' => $hinweis]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['foodalchemist', 'knowledge', 'wissen', 'kanon', 'sicherung', 'wiederherstellung', 'diagnose'],
            'read_only' => true, 'idempotent' => true, 'risk_level' => 'safe',
            'requires_auth' => true, 'requires_team' => true, 'cost_class' => 'local_db',
            'related_tools' => [
                'foodalchemist.knowledge_canon.GET', 'foodalchemist.knowledge_canon.PUT',
                'foodalchemist.knowledge_profil.GET',
            ],
            'examples' => [
                'Ist der Kanon gesichert?',
                'Was ginge verloren, wenn die Datenbank neu aufgesetzt wird?',
                'Liesse sich die Kanon-Sicherung überhaupt noch einspielen?',
            ],
        ];
    }
}
