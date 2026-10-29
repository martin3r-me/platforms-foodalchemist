<?php

namespace Platform\FoodAlchemist\Services\Stammdaten;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistImportZuordnung as Zuordnung;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun as Lauf;

/**
 * Eigene Lieferanten-Dateien ohne Vorlage (Kurator 29.10.2026). Gemeinsame Stelle für Preislisten- und Stammdaten-Import:
 *  - Zuordnung je Lieferant + Art merken und beim nächsten Upload anwenden; ändert sich die Kopfzeile, kommt ein Hinweis (fehlende/neue Spalten).
 *  - Erkennt der Import nicht genug, wird der Lauf „zuordnung_noetig“ (kein Fehler): die Datei bleibt auf dem privaten Disk (Zufallsname, wird nie
 *    ausgeliefert), das Team ordnet in der Oberfläche oder per MCP zu.
 */
class ImportZuordnungService
{
    public const STATUS_ZUORDNUNG_NOETIG = Lauf::STATUS_ZUORDNUNG_NOETIG;

    private const ORDNER = 'fa-import';

    public function norm(string $s): string
    {
        $t = mb_strtolower(trim($s));
        $t = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $t);

        return (string) preg_replace('/[^a-z0-9]/', '', $t);
    }

    /** @param list<string> $kopf */
    public function signatur(array $kopf): string
    {
        return sha1(implode('|', array_map(fn ($k) => $this->norm((string) $k), $kopf)));
    }

    public function laden(Team $team, int $supplierId, string $art): ?Zuordnung
    {
        return Zuordnung::where('team_id', $team->id)->where('supplier_id', $supplierId)->where('art', $art)->first();
    }

    /**
     * Zuordnung des Lieferanten auf die Kopfzeile dieser Datei anwenden.
     *
     * @param  list<string>  $kopf
     * @return array{spalten: array<int, string>, hinweise: list<string>}  Spaltenindex ⇒ Zielfeld; Hinweise zu geänderter Kopfzeile
     */
    public function anwenden(Zuordnung $z, array $kopf): array
    {
        $spalten = [];
        $norm = array_map(fn ($k) => $this->norm((string) $k), $kopf);
        $gemerkt = (array) $z->zuordnung;
        foreach ($norm as $i => $n) {
            if (isset($gemerkt[$n])) {
                $spalten[$i] = (string) $gemerkt[$n];
            }
        }
        $hinweise = [];
        if ($z->kopf_signatur !== $this->signatur($kopf)) {
            $fehlend = array_values(array_diff(array_keys($gemerkt), $norm));
            $neu = [];
            $alt = array_map(fn ($k) => $this->norm((string) $k), (array) $z->kopf);
            foreach ($kopf as $i => $k) {
                if (! in_array($norm[$i], $alt, true) && $norm[$i] !== '') {
                    $neu[] = (string) $k;
                }
            }
            $teile = [];
            if ($fehlend !== []) {
                $teile[] = 'fehlt: ' . implode(', ', array_map(fn ($n) => $gemerkt[$n] . ' (' . $n . ')', $fehlend));
            }
            if ($neu !== []) {
                $teile[] = 'neu: ' . implode(', ', $neu);
            }
            $hinweise[] = 'Die Spalten dieses Lieferanten haben sich seit dem letzten Upload geändert' . ($teile !== [] ? ' (' . implode(' · ', $teile) . ')' : '') . ' — gemerkte Zuordnung wurde angewendet, bitte prüfen.';
        }
        $z->forceFill(['zuletzt_benutzt_at' => now()])->save();

        return ['spalten' => $spalten, 'hinweise' => $hinweise];
    }

    /**
     * Zuordnung merken (ersetzt die bisherige dieses Lieferanten).
     *
     * @param  list<string>  $kopf
     * @param  array<int, string>  $spalten  Spaltenindex ⇒ Zielfeld
     */
    public function speichern(Team $team, int $supplierId, string $art, array $kopf, array $spalten): void
    {
        $map = [];
        foreach ($spalten as $i => $ziel) {
            $n = $this->norm((string) ($kopf[$i] ?? ''));
            if ($n !== '' && $ziel !== '') {
                $map[$n] = $ziel;
            }
        }
        if ($map === []) {
            return;
        }
        Zuordnung::updateOrCreate(
            ['team_id' => $team->id, 'supplier_id' => $supplierId, 'art' => $art],
            ['zuordnung' => $map, 'kopf_signatur' => $this->signatur($kopf), 'kopf' => array_values($kopf), 'zuletzt_benutzt_at' => now()],
        );
    }

    /**
     * Lauf „Zuordnung nötig“: Datei ablegen, Vorschau (Kopf + erste Zeilen) und den Grund festhalten. Kein Fehler — der Lauf wartet.
     *
     * @param  array{kopf: list<string>, zeilen: array<int, list<string>>}  $datei
     * @param  array<int, string>  $vorschlag  Spaltenindex ⇒ Zielfeld (automatisch erkannt)
     */
    public function zuordnungNoetig(Team $team, string $art, string $pfad, ?string $endung, ?string $dateiname, ?int $supplierId, ?int $userId, string $herkunft, ?int $linkId, array $datei, string $grund, array $vorschlag = []): Lauf
    {
        $endung = strtolower($endung ?? (string) pathinfo($pfad, PATHINFO_EXTENSION));
        $endung = preg_match('/^[a-z0-9]{2,5}$/', $endung) ? $endung : 'csv';
        $rel = self::ORDNER . '/' . Str::uuid() . '.' . $endung;
        Storage::disk('local')->put($rel, (string) file_get_contents($pfad));

        return Lauf::create([
            'team_id' => $team->id, 'supplier_id' => $supplierId, 'filename' => basename($dateiname ?? $pfad), 'status' => self::STATUS_ZUORDNUNG_NOETIG,
            'art' => $art, 'herkunft' => $herkunft, 'link_id' => $linkId, 'created_by' => $userId, 'datei_pfad' => $rel, 'datei_endung' => $endung,
            'mapping' => ['grund' => $grund, 'kopf' => array_values($datei['kopf']), 'vorschau' => array_values(array_slice($datei['zeilen'], 0, 5)), 'vorschlag' => $vorschlag],
            'counts' => ['zeilen' => count($datei['zeilen'])],
        ]);
    }

    /** Temp-Kopie der abgelegten Datei zum Einlesen (Aufrufer löscht sie). */
    public function datei(Lauf $lauf): string
    {
        if ($lauf->status !== Lauf::STATUS_ZUORDNUNG_NOETIG || $lauf->datei_pfad === null || ! str_starts_with($lauf->datei_pfad, self::ORDNER . '/')
            || ! Storage::disk('local')->exists($lauf->datei_pfad)) {
            throw new \RuntimeException('Zu diesem Lauf gibt es keine wartende Datei (nicht „Zuordnung nötig“ oder schon verworfen).');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'fa-zuordnen-');
        $ziel = $tmp . '.' . $lauf->datei_endung;
        rename($tmp, $ziel);
        file_put_contents($ziel, Storage::disk('local')->get($lauf->datei_pfad));

        return $ziel;
    }

    /** Wartenden Lauf samt Datei entfernen (nach dem Zuordnen oder auf Wunsch). */
    public function verwerfen(Lauf $lauf): void
    {
        if ($lauf->datei_pfad !== null && str_starts_with($lauf->datei_pfad, self::ORDNER . '/')) {
            Storage::disk('local')->delete($lauf->datei_pfad);
        }
        $lauf->forceFill(['datei_pfad' => null])->save();
        $lauf->delete();
    }

    /**
     * Mapping {Zielfeld: Überschrift|Spaltenindex} (so schreibt es der MCP-Aufrufer) → Spaltenindex ⇒ Zielfeld.
     *
     * @param  list<string>  $kopf
     * @param  array<string, string|int>  $mapping
     * @return array<int, string>
     */
    public function ausFeldMapping(array $kopf, array $mapping): array
    {
        $norm = array_map(fn ($k) => $this->norm((string) $k), $kopf);
        $out = [];
        foreach ($mapping as $ziel => $spalte) {
            if ($spalte === '' || $spalte === null) {
                continue;
            }
            $idx = is_int($spalte) || ctype_digit((string) $spalte) ? (int) $spalte : array_search($this->norm((string) $spalte), $norm, true);
            if ($idx === false || ! isset($kopf[$idx])) {
                throw new \RuntimeException("Mapping [{$ziel}] verweist auf eine Spalte, die es nicht gibt: {$spalte}");
            }
            $out[(int) $idx] = (string) $ziel;
        }

        return $out;
    }
}
