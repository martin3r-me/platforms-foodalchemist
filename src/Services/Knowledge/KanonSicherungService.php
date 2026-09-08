<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Spec 52 · Paket 3 — der Kanon bekommt einen Wiederherstellungs-Pfad.
 *
 * **Das Loch (H7):** der Kanon existiert ausschliesslich als Zeilen in der Live-DB. Kein
 * Seeder, keine Migration, kein Export — die Migration legt die Tabelle bewusst leer an, und
 * die einzige Schreibstelle ist `knowledge_canon.PUT` über MCP.
 *
 * Für eine frische Umgebung (neuer Kunde, Disaster Recovery, neue Dev-Maschine) heisst das:
 * **kein Kanon.** Und weil `hasCanon()` dann `false` liefert, schaltet der Gateway die alten
 * Bindungen wieder scharf — die auf einer frischen DB ebenfalls nicht existieren. Ergebnis:
 * die Generatoren laufen ohne Regelwerk, ohne dass irgendetwas fehlschlägt. Nur schlechtere
 * Rezepte. Deshalb steht in Paket 2 eine Routing-Abweichung bewusst offen
 * (`ai_generate_recipe × regelwerk`: Seed will `none`, Migration setzt `always`) — `none` ist
 * erst richtig, wenn der Kanon verlässlich da ist.
 *
 * **Kein Seeder mit hartkodierter Liste.** Der Kanon ist Kuration, keine Politik: welche
 * §-Dossiers verbindlich sind, entscheidet ein Mensch und ändert sich. Eine Liste im Code
 * würde sofort driften — dieselbe Falle wie bei den vier Routing-Schreibern (`H1`).
 * Stattdessen eine **exportierte, versionierte Datei**, die der Betrieb erzeugt und das Repo
 * trägt.
 *
 * Die Rechnung lebt hier und nicht im Kommando, weil sie drei Abnehmer hat: das Kommando
 * `foodalchemist:wissen-kanon-sicherung`, das Tool `foodalchemist.knowledge_kanon_sicherung.GET`
 * (Agent — und der einzige Weg auf demo, dort gibt es keine Shell) und die Tests. Zwei
 * Implementierungen derselben Frage wären genau die Doppelung, die diese Spec abbaut.
 */
class KanonSicherungService
{
    /** Felder, die eine Sicherungs-Zeile trägt — und die der Abgleich vergleicht. */
    public const FELDER = ['scope', 'scope_key', 'role', 'ord', 'mode', 'slug', 'active', 'global'];

    public function __construct(private readonly KnowledgeCanonService $canon) {}

    /** Standard-Ablage im Repo: `database/kanon/kanon-team-<id>.json`. */
    public function standardDatei(int $teamId): string
    {
        return dirname(__DIR__, 3).'/database/kanon/kanon-team-'.$teamId.'.json';
    }

    /**
     * Der zu sichernde Stand.
     *
     * Bewusst über `list(includeInactive: true)` und NICHT über `documentsFor()`: gesichert
     * wird die KURATION, nicht ihr heutiges Ergebnis. Eine Zeile auf ein gerade deaktiviertes
     * Dossier ist Teil der Entscheidung und gehört mit — sonst verlöre die Sicherung genau
     * das, was der Integritäts-Bericht als Befund meldet.
     *
     * @return list<array<string, mixed>>
     */
    public function zeilen(Team $team): array
    {
        return collect($this->canon->list($team, includeInactive: true))
            ->map(fn ($z) => [
                'scope' => (string) $z['scope'], 'scope_key' => (string) $z['scope_key'],
                'role' => (string) $z['role'], 'ord' => (int) $z['ord'],
                'mode' => (string) $z['mode'], 'slug' => (string) $z['slug'],
                'active' => (bool) $z['active'], 'global' => (bool) $z['global'],
            ])
            // Stabil sortiert, damit zwei Exporte desselben Standes zeichengleiche Dateien
            // ergeben — sonst rauscht jeder Export als Diff durch den Review.
            ->sortBy([['scope', 'asc'], ['scope_key', 'asc'], ['role', 'asc'], ['ord', 'asc'], ['slug', 'asc']])
            ->values()->all();
    }

    /** @return array{erzeugt_am: string, team_id: int, zeilen: list<array<string, mixed>>} */
    public function inhalt(Team $team): array
    {
        return ['erzeugt_am' => now()->toDateString(), 'team_id' => (int) $team->id, 'zeilen' => $this->zeilen($team)];
    }

    /**
     * Datei lesen und den Vertrag prüfen — lieber hier hart fehlschlagen als beim Schreiben
     * die Hälfte gesetzt haben.
     *
     * @return list<array<string, mixed>>
     */
    public function lade(string $datei): array
    {
        if (! is_file($datei)) {
            throw new InvalidArgumentException('Datei nicht gefunden: '.$datei);
        }
        $inhalt = json_decode((string) file_get_contents($datei), true);
        if (! is_array($inhalt) || ! is_array($inhalt['zeilen'] ?? null)) {
            throw new InvalidArgumentException('Datei ist keine Kanon-Sicherung (Schlüssel `zeilen` fehlt).');
        }

        $zeilen = [];
        foreach ($inhalt['zeilen'] as $i => $z) {
            if (! is_array($z)) {
                throw new InvalidArgumentException("Zeile {$i} ist kein Objekt.");
            }
            foreach (['scope', 'scope_key', 'role', 'mode', 'slug'] as $pflicht) {
                if (trim((string) ($z[$pflicht] ?? '')) === '') {
                    throw new InvalidArgumentException("Zeile {$i}: `{$pflicht}` fehlt.");
                }
            }
            // Enums gegen den Service prüfen, nicht gegen eine eigene Liste: als `achse` in Paket 2
            // dazukam, hätte eine zweite Liste hier still eine gültige Sicherung abgelehnt.
            foreach ([['scope', KnowledgeCanonService::SCOPES], ['role', KnowledgeCanonService::ROLES], ['mode', KnowledgeCanonService::MODES]] as [$feld, $erlaubt]) {
                if (! in_array($z[$feld], $erlaubt, true)) {
                    throw new InvalidArgumentException("Zeile {$i}: `{$feld}` = «{$z[$feld]}» — erlaubt: ".implode('|', $erlaubt));
                }
            }
            $zeilen[] = [
                'scope' => (string) $z['scope'], 'scope_key' => (string) $z['scope_key'],
                'role' => (string) $z['role'], 'ord' => (int) ($z['ord'] ?? 0),
                'mode' => (string) $z['mode'], 'slug' => (string) $z['slug'],
                'active' => (bool) ($z['active'] ?? true), 'global' => (bool) ($z['global'] ?? false),
            ];
        }

        return $zeilen;
    }

    /**
     * Live-Kanon gegen die Sicherung halten — read-only, und die Grundlage für beide Richtungen.
     *
     * Vier Aussagen, bewusst getrennt:
     *   · `nur_live`     — kuratiert, aber NICHT gesichert. Geht bei einem Neuaufbau verloren.
     *   · `nur_datei`    — gesichert, aber live nicht (mehr) da. Zerfällt in zwei Fälle, die
     *                      NICHT dasselbe bedeuten, deshalb zusätzlich `nur_datei_aufloesbar`:
     *                      das Dossier gibt es noch (→ die Verdrahtung wurde entfernt, echte
     *                      Abweichung) oder es gibt es nicht mehr (→ Korpus-Neuschnitt, siehe
     *                      `ohne_dossier`). ★ `ohne_dossier` steckt zwangsläufig IMMER in
     *                      `nur_datei` — eine Kanon-Zeile kann ohne ihr Dossier nicht live
     *                      stehen. Wer die beiden nicht trennt, kann den Neuschnitt-Fall nie
     *                      milder behandeln als den Verlust-Fall.
     *   · `abweichend`   — dieselbe Zeile, anderer Wert (mode/ord/active).
     *   · `ohne_dossier` — die Sicherung nennt einen Slug, den es hier nicht gibt. DAS ist der
     *                      Fall, der einen Neuaufbau still halbiert; nach einem Korpus-Umbau
     *                      der Normalfall, und deshalb ein eigener Befund statt eines Fehlers.
     *
     * Die Zähler heissen `datei_zeilen`/`live_zeilen` und nicht `gesichert`: `gesichert` ist im
     * MCP-Tool die JA/NEIN-Frage „gibt es überhaupt eine Sicherung". Beides unter einem Namen
     * hat dort prompt eine Zahl als Boolean durchgereicht — ein Wort, zwei Bedeutungen.
     *
     * @param  list<array<string, mixed>>  $datei
     * @return array{datei_zeilen: int, live_zeilen: int, deckungsgleich: bool, nur_live: list<string>,
     *   nur_datei: list<string>, nur_datei_aufloesbar: list<string>,
     *   abweichend: list<array<string, mixed>>, ohne_dossier: list<string>}
     */
    public function abgleich(Team $team, array $datei): array
    {
        $schluessel = fn (array $z) => $z['scope'].'|'.$z['scope_key'].'|'.$z['role'].'|'.$z['slug'];
        $live = collect($this->zeilen($team))->keyBy($schluessel);
        $soll = collect($datei)->keyBy($schluessel);

        $abweichend = [];
        foreach ($soll->intersectByKeys($live) as $k => $z) {
            $ist = $live[$k];
            $diff = [];
            foreach (['mode', 'ord', 'active', 'global'] as $f) {
                if ($ist[$f] !== $z[$f]) {
                    $diff[$f] = ['datei' => $z[$f], 'live' => $ist[$f]];
                }
            }
            if ($diff !== []) {
                $abweichend[] = ['zeile' => $k, 'felder' => $diff];
            }
        }

        $ohneDossier = $this->unaufloesbar($team, $datei);
        $nurDatei = $soll->keys()->diff($live->keys())->values();

        return [
            'datei_zeilen' => $soll->count(),
            'live_zeilen' => $live->count(),
            'deckungsgleich' => $soll->keys()->diff($live->keys())->isEmpty()
                && $live->keys()->diff($soll->keys())->isEmpty() && $abweichend === [],
            'nur_live' => $live->keys()->diff($soll->keys())->values()->all(),
            'nur_datei' => $nurDatei->all(),
            // Der Teil von `nur_datei`, der WIRKLICH eine Abweichung ist: das Dossier steht noch,
            // nur die Verdrahtung fehlt. Der Rest ist Neuschnitt und steht in `ohne_dossier`.
            'nur_datei_aufloesbar' => $nurDatei
                ->reject(fn ($k) => in_array((string) $soll[$k]['slug'], $ohneDossier, true))
                ->values()->all(),
            'abweichend' => $abweichend,
            'ohne_dossier' => $ohneDossier,
        ];
    }

    /**
     * Slugs aus der Sicherung, die es in dieser Umgebung nicht (mehr) gibt.
     *
     * @param  list<array<string, mixed>>  $datei
     * @return list<string>
     */
    public function unaufloesbar(Team $team, array $datei): array
    {
        $slugs = array_values(array_unique(array_map(fn ($z) => (string) $z['slug'], $datei)));
        if ($slugs === []) {
            return [];
        }
        // Über TeamScope sichtbar prüfen und nicht per roher Query: sonst meldet der Bericht
        // ein fremdes Team-Dossier als vorhanden, das `set()` gleich darauf ablehnt.
        $vorhanden = TeamScope::applyVisible(
            DB::table(KnowledgeCanonService::DOCS)->whereNull('deleted_at')->whereIn('slug', $slugs), 'team_id', $team
        )->pluck('slug')->map(fn ($s) => (string) $s)->all();

        return array_values(array_diff($slugs, $vorhanden));
    }

    /**
     * Sicherung einspielen. `$apply = false` ist die Vorschau — sie schreibt nichts.
     *
     * Der Import setzt KEINE Dossiers, er verdrahtet nur. Fehlt der Slug, ist das ein Befund
     * für den Menschen und kein Grund abzubrechen: der Rest soll ankommen.
     *
     * @param  list<array<string, mixed>>  $datei
     * @return array{geschrieben: int, uebersprungen: int, fehlend: list<string>, hinweise: list<string>}
     */
    public function spielEin(Team $team, array $datei, bool $apply): array
    {
        $fehlend = $this->unaufloesbar($team, $datei);
        $geschrieben = 0;
        $hinweise = [];

        foreach ($datei as $z) {
            if (in_array($z['slug'], $fehlend, true)) {
                continue;
            }
            if ($apply) {
                // Über den Service, nicht per Insert: Tenancy, Enum-Prüfung, Changelog-Guard und
                // der Deckel-Hinweis leben dort. `active` und `global` MÜSSEN mit — die Sicherung
                // hält bewusst auch deaktivierte Zeilen, und ohne die beiden Felder käme eine
                // stillgelegte Kuration scharf zurück und eine globale als Team-Zeile.
                $ergebnis = $this->canon->set($team, [
                    'scope' => $z['scope'], 'scope_key' => $z['scope_key'], 'role' => $z['role'],
                    'ord' => $z['ord'], 'mode' => $z['mode'], 'slug' => $z['slug'],
                    'active' => $z['active'], 'global' => $z['global'],
                ]);
                foreach ($ergebnis['hinweise'] as $h) {
                    $hinweise[] = $z['slug'].': '.$h;
                }
            }
            $geschrieben++;
        }

        return [
            'geschrieben' => $geschrieben,
            'uebersprungen' => count($datei) - $geschrieben,
            'fehlend' => $fehlend,
            'hinweise' => array_values(array_unique($hinweise)),
        ];
    }
}
