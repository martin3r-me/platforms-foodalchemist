<?php

namespace Platform\FoodAlchemist\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine as Zeile;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * Spec 82 §3 / Schritt 4d — Preislisten-Import für Mandanten, Stufe „Abgleich + Prüfliste".
 *
 * Der Mandant lädt seine Preisliste hoch (CSV). Jede Zeile wird gegen die für sein Team SICHTBAREN Artikel
 * abgeglichen — Artikelnummer + Lieferant zuerst (Regelwerk LA §3), danach EAN. Das Ergebnis wird als Lauf mit
 * Zeilen abgelegt. Diese Stufe schreibt KEINEN Preis und legt KEINEN Artikel an:
 *  - Treffer warten auf die Schreib-Schicht (Teampreis append-only mit valid_from über PriceService, Stufe 3a).
 *  - Alles andere ist die Prüfliste: ohne Treffer, mehrdeutig, Lieferant unbekannt, Datenfehler, doppelt in der Datei.
 * Eine globale Preiszeile wird nie angefasst. Ein neuer Upload ist ein neuer Lauf (nichts wird überschrieben).
 *
 * Sichtbarkeit: Der Abgleich nutzt `visibleToTeam`, ein stamm_paket-Artikel ohne Opt-in ist also kein Treffer.
 *
 * Excel: Das Modul hat keine Tabellen-Abhängigkeit (Entscheidung für Dominique, wie beim Artikel-Datei-Import,
 * {@see FileArticleImportService::liesDatei}). Excel-Dateien werden mit dem Hinweis „als CSV exportieren" abgelehnt.
 */
class PreislistenImportService
{
    public const MAX_ZEILEN = 20000;

    /** Größe der hochgeladenen Datei. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const ENDUNGEN_CSV = ['csv', 'tsv', 'txt'];

    public const ENDUNGEN_EXCEL = ['xlsx', 'xlsm', 'ods'];

    public function __construct(private readonly FaRechte $rechte) {}

    /**
     * Darf `$user` für `$team` eine Preisliste importieren? Nur das Kunden-Hauptteam (Unter-Teams schreiben keine
     * Preise, Spec 82 §3) und mindestens Rolle Kuratieren. Solange Stufe 3a (PriceService::darfTeampreisSchreiben)
     * nicht auf main ist, gilt diese Regel; danach wird sie an die dortige Methode angeglichen.
     */
    public function darfImportieren(Team $team, ?User $user): bool
    {
        return $user !== null
            && (int) $this->rechte->kundenHauptTeam($team)->id === (int) $team->id
            && $this->rechte->darf($user, $team, FaRolle::Kuratieren);
    }

    /** Läufe des Teams, neueste zuerst (strikt team-eigen). @return \Illuminate\Support\Collection<int, FoodAlchemistPriceImportRun> */
    public function laeufe(Team $team, int $limit = 20): \Illuminate\Support\Collection
    {
        return FoodAlchemistPriceImportRun::where('team_id', $team->id)->orderByDesc('id')->limit($limit)->get();
    }

    public function lauf(Team $team, int $id): ?FoodAlchemistPriceImportRun
    {
        return FoodAlchemistPriceImportRun::where('team_id', $team->id)->find($id);
    }

    /**
     * Vorschau für die Spaltenzuordnung: Kopfzeile, automatisch erkannte Zuordnung, die ersten Zeilen.
     * Schreibt nichts. Wirft RuntimeException bei unlesbarer Datei.
     *
     * @return array{kopf: list<string>, vorschlag: array<string,int>, beispiel: list<list<string>>, zeilen: int}
     */
    public function vorschau(string $pfad, ?string $endung = null): array
    {
        $datei = $this->lies($pfad, $endung);
        $vorschlag = [];
        try {
            $vorschlag = $this->ordneSpalten($datei['kopf'], []);
        } catch (\RuntimeException) {
            // Pflichtspalten nicht erkannt: Der Vorschlag bleibt leer, der Nutzer ordnet selbst zu.
            $vorschlag = $this->ordneSpaltenLose($datei['kopf']);
        }

        return ['kopf' => $datei['kopf'], 'vorschlag' => $vorschlag, 'beispiel' => array_slice(array_values($datei['zeilen']), 0, 3), 'zeilen' => count($datei['zeilen'])];
    }

    /** Zielfelder → erkannte Spaltenüberschriften (normalisiert: klein, nur a-z0-9). */
    private const SYNONYME = [
        'supplier' => ['lieferant', 'lieferantname', 'supplier', 'hersteller', 'lieferantenname'],
        'article_number' => ['artikelnummer', 'artikelnr', 'artnr', 'artnummer', 'artikelno', 'bestellnummer', 'bestellnr', 'sku', 'itemnumber', 'artikel'],
        'designation' => ['bezeichnung', 'artikelbezeichnung', 'artikelname', 'name', 'beschreibung', 'artikelbeschreibung', 'designation'],
        'unit' => ['gebinde', 'einheit', 'gebindeeinheit', 'vpe', 'verpackung', 'verpackungseinheit', 'preiseinheit', 'unit'],
        'price' => ['preisnetto', 'nettoek', 'eknetto', 'ekpreisnetto', 'nettoeinkaufspreis', 'nettopreis', 'preis', 'ekpreis', 'ek', 'netto', 'einkaufspreis', 'price', 'preiseur', 'preisnettoeur'],
        'valid_from' => ['gueltigab', 'gueltigvon', 'gueltigkeitab', 'validfrom', 'preisgueltigab', 'abdatum', 'gueltigabdatum'],
        'ean' => ['ean', 'gtin', 'eangebinde', 'eanbestelleinheit', 'eancode'],
    ];

    /**
     * Datei lesen, abgleichen, als Lauf ablegen. Schreibt keine Preise, legt keine Artikel an.
     *
     * ⚠ SICHERHEIT (Auflage Kurator, Spec 82): `stage()` prüft KEINE Berechtigung und vertraut dem Pfad.
     *  - Heute ruft nur der Konsolenbefehl es auf. Kommen Oberfläche oder MCP dazu, MUSS davor
     *    `PriceService::darfTeampreisSchreiben($team)` geprüft werden (nur das Kunden-Hauptteam importiert Preise).
     *  - `$pfad` darf NIE aus Benutzereingabe stammen: Uploads gehören in einen eigenen, vom System erzeugten
     *    Temp-Pfad, nie ein frei wählbarer Pfad (sonst liest der Import beliebige Dateien des Servers).
     *
     * @param  array<string, string|int>  $mapping  Zielfeld => Spaltenüberschrift oder Spaltenindex (leer = automatisch erkennen)
     */
    public function stage(Team $team, string $pfad, array $mapping = [], ?int $supplierId = null, ?int $userId = null, ?string $endung = null, ?string $dateiname = null): FoodAlchemistPriceImportRun
    {
        $datei = $this->lies($pfad, $endung);
        $zuordnung = $this->ordneSpalten($datei['kopf'], $mapping);

        $standard = null;
        if ($supplierId !== null) {
            $standard = FoodAlchemistSupplier::visibleToTeam($team)->whereKey($supplierId)->first()
                ?? throw new \RuntimeException('Lieferant nicht in der Team-Kette sichtbar (supplier prüfen).');
        }
        if ($standard === null && ! isset($zuordnung['supplier'])) {
            throw new \RuntimeException('Die Datei hat keine Lieferant-Spalte: Standard-Lieferant angeben (supplier).');
        }

        $lieferanten = FoodAlchemistSupplier::visibleToTeam($team)->get(['id', 'name'])
            ->groupBy(fn ($s) => $this->norm((string) $s->name));

        return DB::transaction(function () use ($team, $pfad, $dateiname, $datei, $zuordnung, $standard, $lieferanten, $userId) {
            $run = FoodAlchemistPriceImportRun::create([
                'team_id' => $team->id,
                'supplier_id' => $standard?->id,
                'filename' => basename($dateiname ?? $pfad),
                'status' => 'staged',
                'mapping' => $zuordnung,
                'created_by' => $userId,
            ]);

            $zaehler = ['zeilen' => 0, Zeile::STATUS_TREFFER => 0, Zeile::STATUS_OHNE_TREFFER => 0, Zeile::STATUS_MEHRDEUTIG => 0,
                Zeile::STATUS_LIEFERANT_UNBEKANNT => 0, Zeile::STATUS_FEHLER => 0, Zeile::STATUS_DOPPELT => 0];
            $gesehen = [];

            foreach ($datei['zeilen'] as $nr => $felder) {
                $wert = fn (string $feld): string => isset($zuordnung[$feld]) ? trim((string) ($felder[$zuordnung[$feld]] ?? '')) : '';
                $eintrag = [
                    'team_id' => $team->id, 'run_id' => $run->id, 'row_nr' => $nr,
                    'supplier_name' => $this->kuerze($wert('supplier')), 'article_number' => $this->kuerze($wert('article_number'), 64),
                    'ean' => $this->kuerze($wert('ean'), 32), 'designation' => $this->kuerze($wert('designation')),
                    'unit' => $this->kuerze($wert('unit'), 64),
                ];

                $ergebnis = $this->pruefeZeile($team, $wert, $standard, $lieferanten, $gesehen);
                $eintrag = array_merge($eintrag, $ergebnis);
                Zeile::create($eintrag);

                $zaehler['zeilen']++;
                $zaehler[$ergebnis['status']]++;
            }

            $run->update(['counts' => $zaehler]);

            return $run->refresh();
        });
    }

    /** Die Prüfliste eines Laufs: alles außer Treffer. */
    public function pruefliste(FoodAlchemistPriceImportRun $run): \Illuminate\Support\Collection
    {
        return Zeile::where('run_id', $run->id)->where('team_id', $run->team_id)
            ->where('status', '!=', Zeile::STATUS_TREFFER)->orderBy('row_nr')->get();
    }

    /** Die Prüfliste als CSV (Trennzeichen ;, UTF-8 mit BOM für Excel). */
    public function exportPruefliste(FoodAlchemistPriceImportRun $run, string $pfad): int
    {
        $zeilen = $this->pruefliste($run);
        $fp = fopen($pfad, 'w');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, ['zeile', 'status', 'lieferant', 'artikelnummer', 'ean', 'bezeichnung', 'gebinde', 'preis', 'gueltig_ab', 'grund'], ';');
        foreach ($zeilen as $z) {
            fputcsv($fp, [$z->row_nr, $z->status, $z->supplier_name, $z->article_number, $z->ean, $z->designation, $z->unit,
                $z->price !== null ? number_format((float) $z->price, 4, ',', '') : '', $z->valid_from?->format('d.m.Y'), $z->reason], ';');
        }
        fclose($fp);

        return $zeilen->count();
    }

    // ---- intern -----------------------------------------------------------

    /**
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection>  $lieferanten  normalisierter Name → Lieferanten
     * @param  array<string, true>  $gesehen
     * @return array<string, mixed>
     */
    private function pruefeZeile(Team $team, \Closure $wert, ?FoodAlchemistSupplier $standard, $lieferanten, array &$gesehen): array
    {
        $fehler = fn (string $status, string $grund, array $extra = []) => array_merge(
            ['status' => $status, 'reason' => $grund], $extra,
        );

        // 1. Preis und Datum — ohne verwertbaren Preis ist die Zeile kein Preis.
        $preisRoh = $wert('price');
        $preis = $this->zahl($preisRoh);
        if ($preis === null) {
            return $fehler(Zeile::STATUS_FEHLER, $preisRoh === '' ? 'Kein Preis in der Zeile.' : "Preis [{$preisRoh}] ist keine Zahl.");
        }
        if ($preis <= 0) {
            return $fehler(Zeile::STATUS_FEHLER, 'Preis ist 0 oder negativ — ein Null-EK würde die Rezeptkosten auf null ziehen.', ['price' => null]);
        }
        $datumRoh = $wert('valid_from');
        $gueltigAb = Carbon::today();
        if ($datumRoh !== '') {
            $gueltigAb = $this->datum($datumRoh);
            if ($gueltigAb === null) {
                return $fehler(Zeile::STATUS_FEHLER, "Datum [{$datumRoh}] nicht lesbar (erwartet TT.MM.JJJJ oder JJJJ-MM-TT).", ['price' => $preis]);
            }
        }
        $basis = ['price' => $preis, 'valid_from' => $gueltigAb->toDateString()];

        // 2. Lieferant — Spalte gewinnt, sonst Standard-Lieferant.
        $lieferantName = $wert('supplier');
        $lieferant = $standard;
        if ($lieferantName !== '') {
            $kandidaten = $lieferanten->get($this->norm($lieferantName), collect());
            if ($kandidaten->count() !== 1) {
                return $fehler(Zeile::STATUS_LIEFERANT_UNBEKANNT,
                    $kandidaten->isEmpty() ? "Lieferant [{$lieferantName}] ist im Team nicht sichtbar oder unbekannt." : "Lieferant [{$lieferantName}] ist mehrdeutig ({$kandidaten->count()} Treffer).",
                    $basis);
            }
            $lieferant = $kandidaten->first();
        }
        if ($lieferant === null) {
            return $fehler(Zeile::STATUS_LIEFERANT_UNBEKANNT, 'Kein Lieferant in der Zeile und kein Standard-Lieferant.', $basis);
        }
        $basis['supplier_id'] = $lieferant->id;

        // 3. Schlüssel
        $artikelnummer = $wert('article_number');
        $ean = $wert('ean');
        if ($artikelnummer === '' && $ean === '') {
            return $fehler(Zeile::STATUS_FEHLER, 'Weder Artikelnummer noch EAN in der Zeile.', $basis);
        }
        $schluessel = $lieferant->id . '|' . ($artikelnummer !== '' ? 'a:' . $artikelnummer : 'e:' . $ean);
        if (isset($gesehen[$schluessel])) {
            return $fehler(Zeile::STATUS_DOPPELT, 'Derselbe Artikel steht schon weiter oben in der Datei — nur die erste Zeile zählt.', $basis);
        }
        $gesehen[$schluessel] = true;

        // 4. Abgleich: Artikelnummer + Lieferant, danach EAN (nur sichtbare Artikel).
        $sichtbar = fn () => FoodAlchemistSupplierItem::visibleToTeam($team)->where('supplier_id', $lieferant->id);
        if ($artikelnummer !== '') {
            $treffer = $sichtbar()->where('article_number', $artikelnummer)->limit(2)->pluck('id');
            if ($treffer->count() > 1) {
                return $fehler(Zeile::STATUS_MEHRDEUTIG, "Artikelnummer [{$artikelnummer}] trifft mehrere Artikel dieses Lieferanten.", $basis);
            }
            if ($treffer->count() === 1) {
                return array_merge($basis, ['status' => Zeile::STATUS_TREFFER, 'match_method' => 'artikelnummer', 'supplier_item_id' => $treffer->first()]);
            }
        }
        if ($ean !== '') {
            $treffer = $sichtbar()->where(fn ($q) => $q->where('ean_packaging', $ean)->orWhere('ean_ordering', $ean))->limit(2)->pluck('id');
            if ($treffer->count() > 1) {
                return $fehler(Zeile::STATUS_MEHRDEUTIG, "EAN [{$ean}] trifft mehrere Artikel dieses Lieferanten.", $basis);
            }
            if ($treffer->count() === 1) {
                return array_merge($basis, ['status' => Zeile::STATUS_TREFFER, 'match_method' => 'ean', 'supplier_item_id' => $treffer->first()]);
            }
        }

        return $fehler(Zeile::STATUS_OHNE_TREFFER, 'Kein sichtbarer Artikel mit dieser Artikelnummer/EAN beim Lieferanten — in der Prüfliste klären, es wird kein Artikel angelegt.', $basis);
    }

    /** @return array{kopf: list<string>, zeilen: array<int, list<string>>} Zeilen-Schlüssel = Zeilennummer in der Datei */
    private function lies(string $pfad, ?string $endung = null): array
    {
        if (! is_file($pfad) || ! is_readable($pfad)) {
            throw new \RuntimeException('Datei nicht lesbar.');
        }
        if (filesize($pfad) > self::MAX_BYTES) {
            throw new \RuntimeException('Datei ist größer als ' . (self::MAX_BYTES / 1024 / 1024) . ' MB — bitte teilen.');
        }
        $endung = strtolower($endung ?? (string) pathinfo($pfad, PATHINFO_EXTENSION));
        if ($endung === 'xls') {
            throw new \RuntimeException('Das alte Excel-Format .xls wird nicht gelesen. Bitte als .xlsx oder CSV speichern.');
        }
        if (in_array($endung, self::ENDUNGEN_EXCEL, true)) {
            return $this->liesExcel($pfad, $endung);
        }
        if (! in_array($endung, self::ENDUNGEN_CSV, true)) {
            throw new \RuntimeException("Unbekannte Endung [{$endung}] — erwartet .csv, .tsv, .xlsx oder .ods.");
        }
        $roh = (string) file_get_contents($pfad);
        $roh = preg_replace('/^\xEF\xBB\xBF/', '', $roh) ?? $roh;
        if (trim($roh) === '') {
            throw new \RuntimeException('Datei ist leer.');
        }
        if (! mb_check_encoding($roh, 'UTF-8')) {
            $roh = mb_convert_encoding($roh, 'UTF-8', 'Windows-1252');   // typischer Excel-Export
        }

        $ersteZeile = strtok($roh, "\r\n") ?: '';
        $trenner = collect([';', "\t", ',', '|'])->sortByDesc(fn ($t) => substr_count($ersteZeile, $t))->first();

        $fp = fopen('php://temp', 'r+');
        fwrite($fp, $roh);
        rewind($fp);
        $kopf = null;
        $zeilen = [];
        $nr = 0;
        while (($f = fgetcsv($fp, 0, $trenner)) !== false) {
            $nr++;
            if ($f === [null] || trim(implode('', array_map('strval', $f))) === '') {
                continue;
            }
            if ($kopf === null) {
                $kopf = array_map(fn ($v) => trim((string) $v), $f);

                continue;
            }
            if (count($zeilen) >= self::MAX_ZEILEN) {
                throw new \RuntimeException('Mehr als ' . self::MAX_ZEILEN . ' Zeilen — Datei bitte teilen.');
            }
            $zeilen[$nr] = array_map(fn ($v) => trim((string) $v), $f);
        }
        fclose($fp);
        if ($kopf === null) {
            throw new \RuntimeException('Keine Kopfzeile gefunden.');
        }

        return ['kopf' => $kopf, 'zeilen' => $zeilen];
    }

    /**
     * Excel/ODS lesen (erstes Blatt, erste nicht leere Zeile = Kopfzeile). Streamt über OpenSpout, ohne die ganze Datei in den Speicher zu laden.
     * Zahlen mit Nachkommastellen werden als Text mit Komma übergeben, damit „1.234“ nie als Tausender gelesen wird;
     * Datumszellen werden zu JJJJ-MM-TT. Führende Nullen, die Excel schon verloren hat, kommen nicht zurück.
     *
     * @return array{kopf: list<string>, zeilen: array<int, list<string>>}
     */
    private function liesExcel(string $pfad, string $endung): array
    {
        $readerKlasse = $endung === 'ods' ? '\OpenSpout\Reader\ODS\Reader' : '\OpenSpout\Reader\XLSX\Reader';
        if (! class_exists($readerKlasse)) {
            throw new \RuntimeException('Excel-Dateien brauchen die Bibliothek openspout/openspout (composer update auf dem Server nötig). Bitte vorerst als CSV exportieren.');
        }

        $kopf = null;
        $zeilen = [];
        $nr = 0;
        $reader = new $readerKlasse();
        try {
            $reader->open($pfad);
            foreach ($reader->getSheetIterator() as $blatt) {
                foreach ($blatt->getRowIterator() as $zeile) {
                    $nr++;
                    $zellen = array_map(fn ($z) => $this->zelle($z), $zeile->toArray());
                    if (trim(implode('', $zellen)) === '') {
                        continue;
                    }
                    if ($kopf === null) {
                        $kopf = array_map('trim', $zellen);

                        continue;
                    }
                    if (count($zeilen) >= self::MAX_ZEILEN) {
                        throw new \RuntimeException('Mehr als ' . self::MAX_ZEILEN . ' Zeilen — Datei bitte teilen.');
                    }
                    $zeilen[$nr] = array_map('trim', $zellen);
                }
                break;   // nur das erste Blatt
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new \RuntimeException('Die Excel-Datei ließ sich nicht lesen (beschädigt oder kein gültiges Format).');
        } finally {
            try {
                $reader->close();
            } catch (\Throwable) {
            }
        }
        if ($kopf === null) {
            throw new \RuntimeException('Keine Kopfzeile gefunden.');
        }

        return ['kopf' => $kopf, 'zeilen' => $zeilen];
    }

    private function zelle(mixed $wert): string
    {
        return match (true) {
            $wert === null => '',
            is_bool($wert) => $wert ? '1' : '0',
            is_int($wert) => (string) $wert,
            is_float($wert) => floor($wert) === $wert && abs($wert) < 1e15
                ? (string) (int) $wert
                : str_replace('.', ',', rtrim(rtrim(number_format($wert, 6, '.', ''), '0'), '.')),
            $wert instanceof \DateTimeInterface => $wert->format('Y-m-d'),
            default => (string) $wert,
        };
    }

    /** Vorschlag ohne Pflichtprüfung: nur, was sich über Synonyme erkennen lässt. @return array<string,int> */
    private function ordneSpaltenLose(array $kopf): array
    {
        $normKopf = array_map(fn ($t) => $this->norm($t), $kopf);
        $z = [];
        foreach (self::SYNONYME as $feld => $syns) {
            foreach ($syns as $syn) {
                $idx = array_search($syn, $normKopf, true);
                if ($idx !== false && ! in_array($idx, $z, true)) {
                    $z[$feld] = (int) $idx;
                    break;
                }
            }
        }

        return $z;
    }

    /**
     * Zielfeld → Spaltenindex. Explizites Mapping (Überschrift oder Index) gewinnt, sonst Synonyme.
     *
     * @param  list<string>  $kopf
     * @param  array<string, string|int>  $mapping
     * @return array<string, int>
     */
    private function ordneSpalten(array $kopf, array $mapping): array
    {
        $normKopf = array_map(fn ($t) => $this->norm($t), $kopf);
        $zuordnung = [];
        foreach (array_keys(self::SYNONYME) as $feld) {
            if (isset($mapping[$feld]) && $mapping[$feld] !== '') {
                $ziel = $mapping[$feld];
                $idx = is_int($ziel) || ctype_digit((string) $ziel) ? (int) $ziel : array_search($this->norm((string) $ziel), $normKopf, true);
                if ($idx === false || ! isset($kopf[$idx])) {
                    throw new \RuntimeException("Mapping [{$feld}] verweist auf eine Spalte, die es nicht gibt: " . $ziel);
                }
                $zuordnung[$feld] = (int) $idx;

                continue;
            }
            foreach (self::SYNONYME[$feld] as $syn) {
                $idx = array_search($syn, $normKopf, true);
                if ($idx !== false && ! in_array($idx, $zuordnung, true)) {
                    $zuordnung[$feld] = (int) $idx;
                    break;
                }
            }
        }
        if (! isset($zuordnung['price'])) {
            throw new \RuntimeException('Keine Preis-Spalte erkannt (Kopfzeile: ' . implode(' | ', $kopf) . ') — Spalte im Mapping angeben.');
        }
        if (! isset($zuordnung['article_number']) && ! isset($zuordnung['ean'])) {
            throw new \RuntimeException('Weder Artikelnummer- noch EAN-Spalte erkannt (Kopfzeile: ' . implode(' | ', $kopf) . ') — Spalte im Mapping angeben.');
        }

        return $zuordnung;
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
    }

    private function kuerze(string $s, int $max = 255): ?string
    {
        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    /** „1.234,56", „12,5", „12.5", „€ 12,50" → float; unlesbar → null. */
    private function zahl(string $roh): ?float
    {
        $s = preg_replace('/[^\d.,\-]/', '', str_replace(["\xC2\xA0", ' '], '', $roh)) ?? '';
        if ($s === '' || ! preg_match('/\d/', $s)) {
            return null;
        }
        $komma = strrpos($s, ',');
        $punkt = strrpos($s, '.');
        if ($komma !== false && $punkt !== false) {
            $s = $komma > $punkt ? str_replace(',', '.', str_replace('.', '', $s)) : str_replace(',', '', $s);
        } elseif ($komma !== false) {
            $s = str_replace(',', '.', $s);
        } elseif ($punkt !== false && preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) {
            $s = str_replace('.', '', $s);   // 1.234 = Tausender
        }

        return is_numeric($s) ? (float) $s : null;
    }

    private function datum(string $roh): ?Carbon
    {
        // Excel-Seriennummer (Tage seit 30.12.1899), wenn die Zelle kein Datumsformat trug: 1982 bis 2119.
        if (ctype_digit($roh) && (int) $roh >= 30000 && (int) $roh <= 80000) {
            return Carbon::create(1899, 12, 30)->startOfDay()->addDays((int) $roh);
        }
        foreach (['d.m.Y', 'd.m.y', 'Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            try {
                $d = Carbon::createFromFormat('!' . $format, $roh);
                if ($d !== false && $d->format($format) === $roh) {
                    return $d;
                }
            } catch (\Throwable) {
                // nächstes Format
            }
        }

        return null;
    }
}
