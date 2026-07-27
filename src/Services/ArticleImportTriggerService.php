<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\ImportArticlesJob;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;

/**
 * Spec 13 · S3b — die **Auslösung** des Kanal-B-Imports, nicht ein zweiter Import-Pfad.
 * Geschrieben wird weiterhin ausschließlich von {@see FileArticleImportService};
 * dieser Service beantwortet nur die drei Fragen, die eine MCP-Fläche aufmacht:
 *
 *  1. **Welche Datei darf gelesen werden?** — genau die, die ein Mensch in den festen
 *     Ablage-Ordner gelegt hat. Der Parameter ist ein **Dateiname**, kein Pfad
 *     ({@see self::pfadFuer()}): ein Tool, das einen freien Pfad annimmt, ist ein
 *     Lese-Zugriff auf das Server-Dateisystem. Der Ordner ist absichtlich **nicht**
 *     konfigurierbar — die Nicht-Konfigurierbarkeit IST die Antwort.
 *  2. **Was passiert ohne `apply`?** — der Trockenlauf, synchron, weil sein Bericht das
 *     Ergebnis IST (er wird nirgends abgelegt). Er ist auf {@see self::MAX_VORSCHAU_ZEILEN}
 *     Zeilen begrenzt, weil ein Tool-Call ein Request ist.
 *  3. **Was passiert mit `apply`?** — der scharfe Lauf geht über einen Job. Er rechnet die
 *     ganze betroffene Rezept-Menge neu (seit V-049 ohne Deckel) und gehört damit nicht in
 *     einen synchronen Aufruf. Zurück kommt die `run_id`; die Quittung liest
 *     {@see IngestStatusService} (Block `laeufe`) — deshalb war S3a zuerst fällig.
 *
 * **Kein Lauf-Lock, bewusst:** ein `running`-Lauf sperrt hier nichts, er wird nur als
 * Hinweis mitgegeben. `bulk_runs` kennt kein Ende ohne Erfolg (V-054) — eine Sperre auf
 * `running` wäre im Fehlerfall eine Dauer-Sperre, also genau dann, wenn man importieren
 * müsste.
 */
final class ArticleImportTriggerService
{
    /**
     * Ablage-Ordner, relativ zu `storage/app`. Ein Mensch legt die Quartals-Datei hier ab
     * (SFTP/Deploy-Ordner), das Tool nennt sie nur beim Namen.
     */
    public const ORDNER = 'foodalchemist/import';

    /** Lesbare Endungen — dieselbe Menge, die {@see FileArticleImportService::liesDatei()} akzeptiert. */
    public const ENDUNGEN = ['csv', 'tsv', 'txt'];

    /**
     * Obergrenze der **synchronen** Vorschau. Der Reader selbst kann 20.000 Zeilen
     * (`FileArticleImportService::MAX_ZEILEN`) — die dürfen aber in einem Job laufen,
     * nicht in einem Tool-Call. Größere Datei ⇒ scharf per Job oder Vorschau per Kommando.
     */
    public const MAX_VORSCHAU_ZEILEN = 2000;

    /** Zeilen-Befunde in der Vorschau-Antwort (Rauschen wird vorher gefiltert). */
    public const MAX_BEFUNDE = 200;

    public function __construct(private FileArticleImportService $import)
    {
    }

    /** Absoluter Ablage-Ordner. Wird beim ersten Blick angelegt, damit „leer" und „fehlt" dasselbe sagen. */
    public function ordner(): string
    {
        $pfad = storage_path('app/' . self::ORDNER);
        if (! is_dir($pfad)) {
            @mkdir($pfad, 0775, true);
        }

        return $pfad;
    }

    /**
     * Bereitliegende Dateien. Das ist die Discovery-Fläche des Tools: ohne sie müsste ein
     * Aufrufer Dateinamen raten, und Raten ist bei einem Datei-Parameter die falsche Übung.
     *
     * @return list<array{datei: string, groesse_kb: float, geaendert_at: string, zeilen_geschaetzt: int}>
     */
    public function dateien(): array
    {
        $out = [];
        foreach (self::ENDUNGEN as $endung) {
            foreach (glob($this->ordner() . '/*.' . $endung) ?: [] as $pfad) {
                if (! is_file($pfad)) {
                    continue;
                }
                $out[] = [
                    'datei' => basename($pfad),
                    'groesse_kb' => round(((int) filesize($pfad)) / 1024, 1),
                    'geaendert_at' => date('c', (int) filemtime($pfad)),
                    'zeilen_geschaetzt' => $this->zeilenSchaetzung($pfad),
                ];
            }
        }
        usort($out, fn ($a, $b) => strcmp($b['geaendert_at'], $a['geaendert_at']));

        return $out;
    }

    /**
     * Dateiname → absoluter Pfad im Ablage-Ordner. **Die Sicherheits-Naht des Teilschritts.**
     *
     * Vier Prüfungen, jede gegen einen eigenen Fall: `basename()`-Gleichheit schließt
     * Verzeichnis-Anteile und `..` aus (auch die eines absoluten Pfades), die Endungs-Liste
     * schließt fremde Dateiarten aus, `realpath()`-Präfix schließt einen Symlink aus dem
     * Ordner heraus aus, und die Existenz-Prüfung kommt zuletzt, damit die Fehlermeldung
     * die *bereitliegenden* Dateien nennen kann statt nur „nicht gefunden".
     *
     * @throws \InvalidArgumentException wenn der Name kein zulässiger Dateiname im Ordner ist
     */
    public function pfadFuer(string $datei): string
    {
        $datei = trim($datei);
        if ($datei === '') {
            throw new \InvalidArgumentException('Kein Dateiname angegeben.');
        }
        if (basename($datei) !== $datei || str_contains($datei, '..') || str_starts_with($datei, '.')) {
            throw new \InvalidArgumentException(
                "\"{$datei}\" ist kein Dateiname. Erwartet wird der reine Name einer Datei im Ablage-Ordner "
                . self::ORDNER . ' (kein Pfad, keine Verzeichnis-Wechsel) — Pfade werden nicht gelesen.'
            );
        }
        $endung = strtolower((string) pathinfo($datei, PATHINFO_EXTENSION));
        if (! in_array($endung, self::ENDUNGEN, true)) {
            throw new \InvalidArgumentException(
                "Endung [{$endung}] wird nicht gelesen — erwartet " . implode('/', self::ENDUNGEN)
                . '. Tabellen-Dateien (xlsx/ods) vorher als CSV (Trennzeichen ;) exportieren.'
            );
        }

        $ordner = $this->ordner();
        $pfad = $ordner . '/' . $datei;
        $echt = realpath($pfad);
        if ($echt === false || ! is_file($echt) || ! is_readable($echt)) {
            $verfuegbar = array_column($this->dateien(), 'datei');

            throw new \InvalidArgumentException(
                "Datei \"{$datei}\" liegt nicht im Ablage-Ordner " . self::ORDNER . '. '
                . ($verfuegbar === []
                    ? 'Der Ordner ist leer — die Datei muss dort abgelegt werden (der Import liest nichts von anderswo).'
                    : 'Bereit liegen: ' . implode(', ', $verfuegbar) . '.')
            );
        }
        // Symlink-Ausbruch: der aufgelöste Pfad muss im aufgelösten Ordner liegen.
        $ordnerEcht = realpath($ordner);
        if ($ordnerEcht === false || ! str_starts_with($echt, $ordnerEcht . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException("Datei \"{$datei}\" zeigt aus dem Ablage-Ordner heraus und wird nicht gelesen.");
        }

        return $echt;
    }

    /**
     * Trockenlauf, synchron. Nichts wird geschrieben — auch keine Lauf-Zeile: ein
     * Trockenlauf ist kein Vorgang, sondern eine Frage.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException         Lieferant nicht in der Team-Kette sichtbar (D1)
     * @throws \InvalidArgumentException Datei-/Format-Problem
     */
    public function trockenlauf(Team $team, int $supplierId, string $datei, int $maxBefunde = 25): array
    {
        $supplier = $this->lieferant($team, $supplierId);
        $pfad = $this->pfadFuer($datei);

        $geschaetzt = $this->zeilenSchaetzung($pfad);
        if ($geschaetzt > self::MAX_VORSCHAU_ZEILEN) {
            throw new \InvalidArgumentException(
                "Datei hat ~{$geschaetzt} Zeilen — die synchrone Vorschau geht bis " . self::MAX_VORSCHAU_ZEILEN
                . '. Für die volle Vorschau: `php artisan foodalchemist:import-articles --file=… --supplier='
                . $supplierId . " --team={$team->id}`. Der scharfe Lauf (apply=true) hat diese Grenze nicht — er läuft als Job."
            );
        }

        $bericht = $this->fuehreAus(fn () => $this->import->importiere($team, $supplierId, $pfad, apply: false));

        return $this->vorschauAntwort($bericht, $datei, $supplier, $maxBefunde);
    }

    /**
     * Scharfer Lauf: Lauf-Zeile anlegen + Job einreihen. Die Datei wird **vor** dem
     * Einreihen einmal vollständig gelesen (Kopfzeile, Trenner, Spalten-Zuordnung) —
     * sonst wäre ein Format-Fehler eine Meldung im Queue-Log statt eine Antwort an den
     * Auslöser.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException         Lieferant nicht in der Team-Kette sichtbar (D1)
     * @throws \InvalidArgumentException Datei-/Format-Problem
     */
    public function starteScharf(Team $team, int $supplierId, string $datei, ?int $userId = null): array
    {
        $supplier = $this->lieferant($team, $supplierId);
        $pfad = $this->pfadFuer($datei);

        $gelesen = $this->fuehreAus(fn () => $this->import->liesDatei($pfad));
        $zeilen = count($gelesen['zeilen']);
        if ($zeilen === 0) {
            throw new \InvalidArgumentException('Datei enthält keine Datenzeile (nur die Kopfzeile).');
        }

        $runId = $this->import->starteRun($team->id, $zeilen, $userId);
        ImportArticlesJob::dispatch($runId, $team->id, $supplierId, $pfad);

        return [
            'modus' => 'scharf',
            'run_id' => $runId,
            'status' => 'running',
            'datei' => $datei,
            'lieferant' => ['id' => (int) $supplier->id, 'name' => (string) $supplier->name],
            'zeilen' => $zeilen,
            'spalten' => $gelesen['spalten'],
            'hinweise' => $gelesen['hinweise'],
            'quittung' => 'Der Lauf ist eingereiht (Queue). Ergebnis über foodalchemist.ingest.STATUS, '
                . "Block `laeufe`: Lauf #{$runId} steht auf `running` und wechselt auf `done` "
                . '(bzw. `failed`, wenn der Job stirbt). Was angekommen ist, sagen dort zusätzlich '
                . '`luecken` und `preis_deltas`.',
            'laufende_laeufe' => $this->laufendeLaeufe($team),
        ];
    }

    /** Lieferant auf die Team-Kette prüfen (D1) — wie im Kommando; unbekannt ist ein Fehler, kein leerer Lauf. */
    private function lieferant(Team $team, int $supplierId): FoodAlchemistSupplier
    {
        $supplier = FoodAlchemistSupplier::visibleToTeam($team)->whereKey($supplierId)->first();
        if ($supplier === null) {
            throw new \RuntimeException("Lieferant #{$supplierId} ist in der Team-Kette nicht sichtbar.");
        }

        return $supplier;
    }

    /**
     * Datei-/Format-Fehler des Importers als `InvalidArgumentException` weitergeben.
     * Damit trennt die Antwort zwei Dinge, die ein Aufrufer unterschiedlich beheben muss:
     * die Datei stimmt nicht (VALIDATION_ERROR) vs. der Lieferant stimmt nicht (NOT_FOUND).
     *
     * @template T
     *
     * @param  \Closure(): T  $fn
     * @return T
     */
    private function fuehreAus(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (\RuntimeException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Vorschau-Antwort: Bilanz + gefilterte Zeilen-Befunde. Gefiltert wird mit demselben
     * Prädikat wie in der Konsolen-Ausgabe ({@see FileArticleImportService::istEreignis()}) —
     * eine unveränderte Zeile ohne Preis-/Detail-Bewegung ist der Normalfall eines
     * Wiederholungslaufs und damit Rauschen.
     *
     * @param  array<string, mixed>  $bericht
     * @return array<string, mixed>
     */
    private function vorschauAntwort(array $bericht, string $datei, FoodAlchemistSupplier $supplier, int $maxBefunde): array
    {
        $maxBefunde = max(0, min(self::MAX_BEFUNDE, $maxBefunde));
        $ereignisse = array_values(array_filter(
            $bericht['befunde'],
            fn (array $b) => FileArticleImportService::istEreignis($b)
        ));

        return [
            'modus' => 'trockenlauf',
            'geschrieben' => false,
            'datei' => $datei,
            'lieferant' => ['id' => (int) $supplier->id, 'name' => (string) $supplier->name],
            'zeilen' => $bericht['zeilen'],
            'bilanz' => [
                'neu' => $bericht['neu'], 'aktualisiert' => $bericht['aktualisiert'],
                'unveraendert' => $bericht['unveraendert'], 'uebersprungen' => $bericht['uebersprungen'],
                'fehler' => $bericht['fehler'],
            ],
            'preise' => $bericht['preise'],
            'details' => $bericht['details'],
            'konditionen' => $bericht['konditionen'],
            'kette' => $bericht['kette'],
            'spalten' => $bericht['spalten'],
            'hinweise' => $bericht['hinweise'],
            'befunde' => array_slice($ereignisse, 0, $maxBefunde),
            'befunde_gesamt' => count($ereignisse),
            'befunde_abgeschnitten' => count($ereignisse) > $maxBefunde,
            'naechster_schritt' => ($bericht['neu'] + $bericht['aktualisiert']) > 0
                ? 'Vor dem scharfen Lauf ein DB-Backup ziehen, dann denselben Aufruf mit apply=true — '
                    . 'der läuft als Job und meldet sich über foodalchemist.ingest.STATUS.'
                : 'Nichts zu schreiben — die Datei bringt keine Änderung.',
        ];
    }

    /**
     * Offene `ingest`-Läufe des Teams. Kein Lock (s. Klassen-Doc), aber eine Auskunft:
     * wer zweimal auslöst, soll das sehen können.
     *
     * @return list<array{run_id: int, gestartet_at: ?string, total: int, done: int}>
     */
    private function laufendeLaeufe(Team $team): array
    {
        return DB::table('foodalchemist_bulk_runs')
            ->where('team_id', $team->id)
            ->where('type', IngestStatusService::LAUF_TYP)
            ->where('status', 'running')
            ->orderByDesc('id')->limit(5)
            ->get(['id', 'created_at', 'total', 'done'])
            ->map(fn ($r) => [
                'run_id' => (int) $r->id,
                'gestartet_at' => $r->created_at !== null ? (string) $r->created_at : null,
                'total' => (int) $r->total,
                'done' => (int) $r->done,
            ])->all();
    }

    /**
     * Zeilen-Schätzung ohne CSV-Parsing: nicht-leere Zeilen minus Kopfzeile. „Geschätzt",
     * weil ein Feld mit eingebettetem Zeilenumbruch mehrfach zählt — für eine Obergrenze
     * reicht das, und es kostet keinen zweiten Parse-Durchlauf.
     */
    private function zeilenSchaetzung(string $pfad): int
    {
        $roh = @file_get_contents($pfad);
        if ($roh === false || trim($roh) === '') {
            return 0;
        }
        $zeilen = preg_split("/\r\n|\n|\r/", $roh) ?: [];
        $gezaehlt = 0;
        foreach ($zeilen as $z) {
            if (trim($z) !== '') {
                $gezaehlt++;
            }
        }

        return max(0, $gezaehlt - 1);
    }
}
