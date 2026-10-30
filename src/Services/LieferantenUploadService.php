<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistLieferantenLink as Link;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine as Zeile;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun as Lauf;
use Platform\FoodAlchemist\Services\Stammdaten\StammdatenImportService;
use Platform\FoodAlchemist\Support\UploadScanner;

/**
 * Portal-Upload (Lieferanten-Link, Stufe 2b). Eine Datei des Lieferanten wird geprüft und landet als Lauf (Herkunft „lieferanten_link“) in derselben
 * Prüfliste wie ein Upload des Teams. Es wird NIE direkt in den Bestand geschrieben: Übernehmen bleibt beim Team (Rolle Kuratieren).
 *
 * Dateiprüfung (alles vor dem Einlesen, nichts wird ausgeführt): Endungs-Whitelist (csv/tsv/txt/xlsx/ods; Makro-Formate xlsm/xlsb/xls abgelehnt),
 * Größe, Datei-Inhalt passt zur Endung (CSV: Text ohne Binärbytes; Excel/ODS: gültiges Zip ohne Makro-/Einbettungs-/Fremdlink-Teile, Zip-Bomben-Grenze),
 * austauschbarer {@see UploadScanner}. Der Lieferant ist fest der des Links: Zeilen anderer Lieferanten werden verworfen. Zellen, die als Formel
 * gelesen werden könnten (=, @, +, − vor Text), werden neutralisiert (Apostroph vorneweg).
 */
class LieferantenUploadService
{
    public const ENDUNGEN = ['csv', 'tsv', 'txt', 'xlsx', 'ods'];

    /** Mehr entpackte Bytes als dies in einer Excel-Datei ⇒ Zip-Bombe. */
    public const MAX_ENTPACKT = 120 * 1024 * 1024;

    public const MAX_EINTRAEGE = 600;

    private const VERBOTENE_TEILE = ['vbaproject.bin', 'externallinks/', 'embeddings/', 'activex/', 'macros/', 'oleobject'];

    public function __construct(
        private readonly LieferantenLinkService $links,
        private readonly PreislistenImportService $preisliste,
        private readonly StammdatenImportService $stammdaten,
        private readonly UploadScanner $scanner,
        private readonly LieferantenUploadBenachrichtigung $benachrichtigung,
    ) {}

    /**
     * @param  'preisliste'|'stammdaten'  $bereich
     * @return array{lauf: Lauf, zeilen: int, zuordnung_noetig: bool}
     *
     * @throws \RuntimeException mit einer Meldung, die dem Lieferanten gezeigt werden darf (kein Pfad, kein Stacktrace)
     */
    public function annehmen(Link $link, string $bereich, UploadedFile $datei, ?Request $request = null): array
    {
        $team = Team::findOrFail($link->team_id);
        try {
            if (! in_array($bereich, ['preisliste', 'stammdaten'], true) || ! in_array($bereich, (array) $link->bereiche, true)) {
                throw new \RuntimeException('Dieser Bereich ist für deinen Zugang nicht freigegeben.');
            }
            $endung = $this->pruefeDatei($datei);
            $pfad = (string) $datei->getRealPath();
            $name = $this->anzeigename((string) $datei->getClientOriginalName(), $endung);

            $lauf = $bereich === 'preisliste'
                ? $this->preisliste->stage($team, $pfad, [], (int) $link->supplier_id, null, $endung, $name)
                : $this->stammdaten->stage($team, $pfad, (int) $link->supplier_id, null, $endung, $name, Lauf::HERKUNFT_LIEFERANTEN_LINK, (int) $link->id);
            $lauf->update(['herkunft' => Lauf::HERKUNFT_LIEFERANTEN_LINK, 'link_id' => $link->id]);
            $lauf = $this->sichere($lauf->refresh());
        } catch (\RuntimeException $e) {
            $this->links->ereignis($link, 'abgelehnt', ['bereich' => $bereich, 'grund' => mb_substr($e->getMessage(), 0, 200)], $request);

            throw $e;
        }

        $zeilen = (int) ($lauf->counts['zeilen'] ?? 0);
        $link->forceFill(['upload_count' => (int) $link->upload_count + 1, 'last_used_at' => now()])->save();
        $this->links->ereignis($link, 'upload', ['bereich' => $bereich, 'lauf_id' => $lauf->id, 'datei' => $lauf->filename, 'zeilen' => $zeilen,
            'zuordnung_noetig' => $lauf->status === Lauf::STATUS_ZUORDNUNG_NOETIG], $request);
        $this->benachrichtigung->melde($link, $lauf);

        return ['lauf' => $lauf, 'zeilen' => $zeilen, 'zuordnung_noetig' => $lauf->status === Lauf::STATUS_ZUORDNUNG_NOETIG];
    }

    /**
     * Prüft Endung, Größe, Inhalt und Scanner. Liefert die geprüfte, kleingeschriebene Endung.
     *
     * @throws \RuntimeException
     */
    public function pruefeDatei(UploadedFile $datei): string
    {
        if (! $datei->isValid()) {
            throw new \RuntimeException('Die Datei konnte nicht hochgeladen werden. Bitte noch einmal versuchen.');
        }
        $endung = strtolower((string) pathinfo((string) $datei->getClientOriginalName(), PATHINFO_EXTENSION));
        if (in_array($endung, ['xlsm', 'xlsb', 'xls', 'xltm'], true)) {
            throw new \RuntimeException('Dateien mit Makros oder im alten Excel-Format nehmen wir nicht an. Bitte als .xlsx oder .csv speichern.');
        }
        if (! in_array($endung, self::ENDUNGEN, true)) {
            throw new \RuntimeException('Dieses Dateiformat nehmen wir nicht an. Erlaubt sind .csv, .xlsx und .ods.');
        }
        $pfad = (string) $datei->getRealPath();
        $groesse = (int) @filesize($pfad);
        if ($groesse <= 0) {
            throw new \RuntimeException('Die Datei ist leer.');
        }
        if ($groesse > PreislistenImportService::MAX_BYTES) {
            throw new \RuntimeException('Die Datei ist größer als ' . (PreislistenImportService::MAX_BYTES / 1024 / 1024) . ' MB. Bitte teilen.');
        }
        in_array($endung, ['xlsx', 'ods'], true) ? $this->pruefeZip($pfad) : $this->pruefeText($pfad);
        if (($grund = $this->scanner->pruefe($pfad, $endung)) !== null) {
            throw new \RuntimeException('Die Datei wurde von der Sicherheitsprüfung abgelehnt.');
        }

        return $endung;
    }

    /** CSV/TSV/TXT: reiner Text. Binärbytes, Programm-/Dokument-Köpfe und zu viele Zeilen werden abgelehnt. */
    private function pruefeText(string $pfad): void
    {
        $roh = (string) file_get_contents($pfad);
        if (str_contains($roh, "\0") || preg_match('/^(MZ|%PDF|PK\x03\x04|\x7FELF|<\?php|<!doctype|<html|<script)/i', ltrim($roh, "\xEF\xBB\xBF \t\r\n"))) {
            throw new \RuntimeException('Der Inhalt sieht nicht nach einer CSV-Tabelle aus.');
        }
        if (substr_count($roh, "\n") > PreislistenImportService::MAX_ZEILEN + 1) {
            throw new \RuntimeException('Zu viele Zeilen (höchstens ' . PreislistenImportService::MAX_ZEILEN . '). Bitte teilen.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($pfad) ?: '';
        if (! str_starts_with($mime, 'text/') && $mime !== 'application/csv' && $mime !== 'inode/x-empty') {
            throw new \RuntimeException('Der Inhalt sieht nicht nach einer CSV-Tabelle aus.');
        }
    }

    /** XLSX/ODS: gültiges Zip, kein Makro, keine Einbettung, keine Fremd-Verknüpfung, keine Zip-Bombe. */
    private function pruefeZip(string $pfad): void
    {
        if (! str_starts_with((string) file_get_contents($pfad, false, null, 0, 4), "PK\x03\x04")) {
            throw new \RuntimeException('Die Datei ist keine gültige Excel-/ODS-Datei.');
        }
        $zip = new \ZipArchive;
        if ($zip->open($pfad, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Die Datei ist keine gültige Excel-/ODS-Datei.');
        }
        try {
            if ($zip->numFiles > self::MAX_EINTRAEGE) {
                throw new \RuntimeException('Die Datei ist ungewöhnlich aufgebaut und wird nicht angenommen.');
            }
            $summe = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                $name = strtolower((string) ($st['name'] ?? ''));
                $summe += (int) ($st['size'] ?? 0);
                foreach (self::VERBOTENE_TEILE as $teil) {
                    if (str_contains($name, $teil)) {
                        throw new \RuntimeException('Dateien mit Makros, Einbettungen oder externen Verknüpfungen nehmen wir nicht an. Bitte als reine Tabelle speichern.');
                    }
                }
                if ($summe > self::MAX_ENTPACKT) {
                    throw new \RuntimeException('Die Datei ist entpackt zu groß und wird nicht angenommen.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Nachbereitung des Laufs: nur der Lieferant des Links, Formel-Neutralisierung. Wird auch nach „Zuordnung nötig“ (Team ordnet zu) aufgerufen.
     */
    public function sichere(Lauf $lauf): Lauf
    {
        if ($lauf->herkunft !== Lauf::HERKUNFT_LIEFERANTEN_LINK || ! $lauf->link_id) {
            return $lauf;
        }
        $supplierId = (int) Link::whereKey($lauf->link_id)->value('supplier_id');
        $counts = (array) $lauf->counts;
        $fremd = 0;
        $lauf->lines()->orderBy('id')->chunkById(500, function ($zeilen) use ($supplierId, $lauf, &$counts, &$fremd) {
            foreach ($zeilen as $z) {
                $upd = $this->neutralisiere($z);
                if ($z->supplier_id !== null && (int) $z->supplier_id !== $supplierId) {
                    $alt = $lauf->art === Lauf::ART_STAMMDATEN ? (string) $z->aktion : (string) $z->status;
                    if (isset($counts[$alt]) && $counts[$alt] > 0) {
                        $counts[$alt]--;
                    }
                    $counts['fehler'] = ($counts['fehler'] ?? 0) + 1;
                    $fremd++;
                    $upd += ['reason' => 'Zeile gehört nicht zu deinem Lieferanten und wurde nicht übernommen.', 'entscheidung' => Zeile::ENTSCHEIDUNG_VERWERFEN]
                        + ($lauf->art === Lauf::ART_STAMMDATEN ? ['aktion' => Zeile::AKTION_FEHLER] : ['status' => Zeile::STATUS_FEHLER]);
                }
                if ($upd !== []) {
                    $z->forceFill($upd)->save();
                }
            }
        });
        if ($fremd > 0) {
            $counts['fremder_lieferant'] = $fremd;
            $lauf->update(['counts' => $counts]);
        }

        return $lauf->refresh();
    }

    /** @return array<string, mixed> geänderte Felder der Zeile */
    private function neutralisiere(Zeile $z): array
    {
        $upd = [];
        foreach (['supplier_name', 'article_number', 'ean', 'designation', 'unit'] as $f) {
            if (is_string($z->{$f}) && ($neu = self::entschaerfe($z->{$f})) !== $z->{$f}) {
                $upd[$f] = $neu;
            }
        }
        if (is_array($z->daten)) {
            $neu = array_map(fn ($v) => is_string($v) ? self::entschaerfe($v) : $v, $z->daten);
            if ($neu !== $z->daten) {
                $upd['daten'] = $neu;
            }
        }

        return $upd;
    }

    /** Text, der in Excel/Calc als Formel laufen würde, bekommt ein Apostroph vorneweg; echte Zahlen (auch „-5“, „+3,5“) bleiben. */
    public static function entschaerfe(string $wert): string
    {
        if ($wert === '' || ! preg_match('/^[=+\-@\t\r]/', $wert)) {
            return $wert;
        }
        if (preg_match('/^[+\-]?\s*[\d.,]+(\s*(%|€|eur))?$/iu', trim($wert))) {
            return $wert;
        }

        return "'" . $wert;
    }

    private function anzeigename(string $roh, string $endung): string
    {
        $basis = preg_replace('/[^\p{L}\p{N} ._\-]+/u', '_', pathinfo($roh, PATHINFO_FILENAME)) ?: 'upload';

        return mb_substr($basis, 0, 80) . '.' . $endung;
    }
}
