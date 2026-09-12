<?php

namespace Platform\FoodAlchemist\Services\Knowledge;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\KnowledgeService;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * Sicherung der EINORDNUNG — das Gegenstück zu {@see KanonSicherungService}.
 *
 * Der Kanon sagt, welches Dossier in welchen Prompt MUSS. Die Einordnung sagt, was ein Dossier
 * IST: seine Art, wo es gilt, welche Zahlen es trägt und unter welchen Begriffen die KI es
 * findet. Beides ist Kuration — also menschliches Urteil, nicht ableitbar aus dem Inhalt.
 *
 * **Warum es diese Datei geben muss:** `art`, `geltung`, `datenwerte` und die Aliase leben
 * ausschliesslich in der Datenbank. Der Vault-Export trägt sie nicht mit — sein `_manifest.json`
 * führt Slug, Titel, Version und Hash, aber keine Einordnung ({@see \Platform\FoodAlchemist\Console\KnowledgeExportCommand}).
 * Ein Korpus-Durchgang über 1.074 Dossiers ist damit ohne Sicherung ein Tag Arbeit, der bei
 * einem Datenbankverlust ersatzlos weg ist.
 *
 * Geschlüsselt wird auf den **Slug**, wie beim Kanon. Das ist bewusst: Slugs sind stabil, IDs
 * sind es über eine Neuinstallation hinweg nicht. Die Folge ist dieselbe wie dort — nach einer
 * Umbenennung muss neu exportiert werden, sonst findet `pruefen` die Zeile nicht mehr wieder.
 *
 * Gesichert wird auch die Einordnung INAKTIVER Dossiers: ein stillgelegtes Dossier bleibt ein
 * eingeordnetes, und das Stilllegen ist eine Entscheidung, die man zurücknehmen können muss.
 */
class EinordnungSicherungService
{
    /** @var list<string> */
    public const FELDER = ['slug', 'art', 'geltung', 'datenwerte', 'aliase'];

    public function standardDatei(int $teamId): string
    {
        return dirname(__DIR__, 3).'/database/einordnung/einordnung-team-'.$teamId.'.json';
    }

    /**
     * Der zu sichernde Stand: jedes sichtbare Dossier, das überhaupt eine Einordnung trägt.
     *
     * Dossiers ohne Art, ohne Geltung, ohne Datenwerte und ohne Alias stehen NICHT in der Datei
     * — sie tragen keine Entscheidung, und eine Datei mit 1.074 Leerzeilen verdeckt nur, was
     * wirklich kuratiert ist.
     *
     * @return list<array<string, mixed>>
     */
    public function zeilen(Team $team): array
    {
        $aliase = DB::table('foodalchemist_knowledge_aliases')
            ->orderBy('alias_slug')->get(['knowledge_document_id', 'alias_slug'])
            ->groupBy('knowledge_document_id')
            ->map(fn ($g) => $g->pluck('alias_slug')->values()->all());

        $docs = TeamScope::applyVisible(
            DB::table('foodalchemist_knowledge_documents')->whereNull('deleted_at'),
            'team_id', $team
        )->orderBy('slug')->get(['id', 'slug', 'art', 'geltung', 'datenwerte']);

        $zeilen = [];
        foreach ($docs as $d) {
            $geltung = json_decode((string) ($d->geltung ?? '[]'), true) ?: [];
            $werte = json_decode((string) ($d->datenwerte ?? '[]'), true) ?: [];
            $eigene = $aliase[$d->id] ?? [];
            if ($d->art === null && $geltung === [] && $werte === [] && $eigene === []) {
                continue;
            }
            $zeilen[] = [
                'slug' => (string) $d->slug,
                'art' => $d->art,
                'geltung' => $geltung,
                'datenwerte' => $werte,
                'aliase' => $eigene,
            ];
        }

        return $zeilen;   // nach Slug sortiert: zwei Exporte desselben Standes sind zeichengleich
    }

    /** @return array{erzeugt_am: string, team_id: int, zeilen: list<array<string, mixed>>} */
    public function inhalt(Team $team): array
    {
        return ['erzeugt_am' => now()->toDateString(), 'team_id' => (int) $team->id, 'zeilen' => $this->zeilen($team)];
    }

    /**
     * Datei lesen und den Vertrag prüfen — lieber hier hart fehlschlagen als beim Einspielen
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
            throw new InvalidArgumentException('Datei ist keine Einordnungs-Sicherung (Schlüssel `zeilen` fehlt).');
        }

        $zeilen = [];
        foreach ($inhalt['zeilen'] as $i => $z) {
            if (! is_array($z) || trim((string) ($z['slug'] ?? '')) === '') {
                throw new InvalidArgumentException("Zeile {$i}: `slug` fehlt.");
            }
            // Die Art gegen den Code prüfen, nicht gegen eine eigene Liste — eine zweite Liste
            // hier würde eine gültige Sicherung still ablehnen, sobald eine Art dazukommt.
            if ($z['art'] !== null && ! in_array($z['art'], Wissensart::ALLE, true)) {
                throw new InvalidArgumentException("Zeile {$i}: `art` = «{$z['art']}» — erlaubt: ".implode('|', Wissensart::ALLE));
            }
            $zeilen[] = [
                'slug' => (string) $z['slug'],
                'art' => $z['art'] ?? null,
                'geltung' => is_array($z['geltung'] ?? null) ? $z['geltung'] : [],
                'datenwerte' => is_array($z['datenwerte'] ?? null) ? $z['datenwerte'] : [],
                'aliase' => is_array($z['aliase'] ?? null) ? array_values(array_filter(array_map('strval', $z['aliase']))) : [],
            ];
        }

        return $zeilen;
    }

    /**
     * Datei gegen den Live-Stand halten. Vier getrennte Aussagen, weil sie verschiedene
     * Handlungen verlangen — dieselbe Aufteilung wie bei der Kanon-Sicherung.
     *
     * `nur_datei` ist der teure Fall: die Datei kennt eine Einordnung, die live fehlt. Entweder
     * wurde sie zurückgenommen (dann ist die Datei alt), oder sie ist verlorengegangen.
     *
     * @param  list<array<string, mixed>>  $datei
     * @return array{nur_live: list<string>, nur_datei: list<string>, abweichend: list<array<string, mixed>>, ohne_dossier: list<string>, deckungsgleich: int}
     */
    public function abgleich(Team $team, array $datei): array
    {
        $live = collect($this->zeilen($team))->keyBy('slug');
        $ausDatei = collect($datei)->keyBy('slug');

        $vorhanden = TeamScope::applyVisible(
            DB::table('foodalchemist_knowledge_documents')->whereNull('deleted_at'),
            'team_id', $team
        )->pluck('slug')->flip();

        $nurLive = $live->keys()->diff($ausDatei->keys())->values()->all();
        $nurDatei = [];
        $ohneDossier = [];
        $abweichend = [];
        $gleich = 0;

        foreach ($ausDatei as $slug => $z) {
            if (! $vorhanden->has($slug)) {
                // Die Sicherung liesse sich für diese Zeile gar nicht mehr einspielen.
                $ohneDossier[] = $slug;

                continue;
            }
            $l = $live->get($slug);
            if ($l === null) {
                $nurDatei[] = $slug;

                continue;
            }
            $diff = [];
            foreach (['art', 'geltung', 'datenwerte', 'aliase'] as $feld) {
                if ($l[$feld] !== $z[$feld]) {
                    $diff[$feld] = ['live' => $l[$feld], 'datei' => $z[$feld]];
                }
            }
            $diff === [] ? $gleich++ : $abweichend[] = ['slug' => $slug] + $diff;
        }

        return ['nur_live' => $nurLive, 'nur_datei' => $nurDatei, 'abweichend' => $abweichend,
            'ohne_dossier' => $ohneDossier, 'deckungsgleich' => $gleich];
    }

    /**
     * Die Sicherung einspielen. Ohne `$apply` nur eine Vorschau — dieselbe Rechnung, nur ohne
     * Schreibzugriff.
     *
     * Geschrieben wird über {@see KnowledgeService::update()} und `addAlias()`, NIE direkt in die
     * Tabelle: sonst entstünde ein zweiter Schreibpfad, der die Vokabular-Prüfung und das
     * Schreibrecht umgeht. Ein Fehler je Zeile kippt den Lauf nicht — er wird gemeldet.
     *
     * Aliase werden nur ERGÄNZT, nie entfernt. Ein Alias, den jemand seit dem Export bewusst
     * gesetzt hat, verschwindet nicht, weil eine alte Datei ihn nicht kennt.
     *
     * @param  list<array<string, mixed>>  $datei
     * @return array{gesetzt: int, aliase: int, uebersprungen: int, fehler: list<array<string, string>>}
     */
    public function spielEin(Team $team, array $datei, bool $apply): array
    {
        $service = app(KnowledgeService::class);
        $gesetzt = 0;
        $aliasZahl = 0;
        $uebersprungen = 0;
        $fehler = [];

        foreach ($datei as $z) {
            $slug = (string) $z['slug'];
            try {
                $doc = $service->findAenderbar($team, $slug, 'einordenbar');
            } catch (\RuntimeException $e) {
                $fehler[] = ['slug' => $slug, 'grund' => $e->getMessage()];

                continue;
            }

            $daten = ['art' => $z['art'] ?? '', 'geltung' => $z['geltung'], 'datenwerte' => $z['datenwerte']];
            $schonGleich = ($doc->art ?? null) === ($z['art'] ?? null)
                && (json_decode((string) ($doc->geltung ?? '[]'), true) ?: []) === $z['geltung']
                && (json_decode((string) ($doc->datenwerte ?? '[]'), true) ?: []) === $z['datenwerte'];

            if ($schonGleich) {
                $uebersprungen++;
            } elseif ($apply) {
                try {
                    $service->update($team, $slug, $daten);
                    $gesetzt++;
                } catch (\RuntimeException|\InvalidArgumentException $e) {
                    $fehler[] = ['slug' => $slug, 'grund' => $e->getMessage()];

                    continue;
                }
            } else {
                $gesetzt++;
            }

            foreach ($z['aliase'] as $alias) {
                if ($apply) {
                    try {
                        $service->addAlias($team, $slug, $alias);
                        $aliasZahl++;
                    } catch (\RuntimeException $e) {
                        $fehler[] = ['slug' => $slug, 'grund' => 'Alias «'.$alias.'»: '.$e->getMessage()];
                    }
                } else {
                    $aliasZahl++;
                }
            }
        }

        return ['gesetzt' => $gesetzt, 'aliase' => $aliasZahl, 'uebersprungen' => $uebersprungen, 'fehler' => $fehler];
    }
}
