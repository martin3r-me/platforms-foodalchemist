<?php

namespace Platform\FoodAlchemist\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
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

    public function __construct(private readonly FaRechte $rechte, private readonly PriceService $preise) {}

    /**
     * Darf `$user` für `$team` eine Preisliste importieren und anwenden? Nur das Kunden-Hauptteam
     * ({@see PriceService::darfTeampreisSchreiben}: Unter-Teams schreiben keine Preise, Spec 82 §3) und mindestens Rolle Kuratieren.
     */
    public function darfImportieren(Team $team, ?User $user): bool
    {
        return $user !== null
            && PriceService::darfTeampreisSchreiben($team)                       // Stufe 3a: nur das Kunden-Hauptteam schreibt Preise
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
    public function stage(Team $team, string $pfad, array $mapping = [], ?int $supplierId = null, ?int $userId = null, ?string $endung = null, ?string $dateiname = null, bool $merken = true): FoodAlchemistPriceImportRun
    {
        $datei = $this->lies($pfad, $endung);
        // Eigene Dateien ohne Vorlage (1a2): gemerkte Zuordnung des Lieferanten anwenden; reicht die Erkennung nicht, wartet der Lauf auf die Zuordnung.
        $zuo = app(\Platform\FoodAlchemist\Services\Stammdaten\ImportZuordnungService::class);
        $explizit = $mapping !== [];
        $kopfHinweise = [];
        if (! $explizit && $supplierId !== null && ($gemerkt = $zuo->laden($team, $supplierId, FoodAlchemistPriceImportRun::ART_PREISLISTE)) !== null) {
            $a = $zuo->anwenden($gemerkt, $datei['kopf']);
            foreach ($a['spalten'] as $i => $feld) {
                $mapping[$feld] = $i;
            }
            $kopfHinweise = $a['hinweise'];
        }
        try {
            $zuordnung = $this->ordneSpalten($datei['kopf'], $mapping);
        } catch (\RuntimeException $e) {
            if (! preg_match('/Keine Preis-Spalte|Weder Artikelnummer/u', $e->getMessage())) {
                throw $e;
            }

            return $zuo->zuordnungNoetig($team, FoodAlchemistPriceImportRun::ART_PREISLISTE, $pfad, $endung, $dateiname, $supplierId, $userId, 'user', null, $datei,
                $e->getMessage(), array_flip($this->ordneSpaltenLose($datei['kopf'])));
        }
        if ($explizit && $merken && $supplierId !== null) {
            $zuo->speichern($team, $supplierId, FoodAlchemistPriceImportRun::ART_PREISLISTE, $datei['kopf'], array_flip($zuordnung));
        }

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

        return DB::transaction(function () use ($team, $pfad, $dateiname, $datei, $zuordnung, $standard, $lieferanten, $userId, $kopfHinweise) {
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

            $run->update(['counts' => $zaehler + ($kopfHinweise !== [] ? ['hinweise' => $kopfHinweise] : [])]);

            return $run->refresh();
        });
    }

    /**
     * „Zuordnung nötig“ abschließen: die abgelegte Datei mit der gewählten Zuordnung abgleichen; der wartende Lauf wird danach entfernt.
     *
     * @param  array<string, string|int>  $mapping  Zielfeld ⇒ Überschrift|Spaltenindex
     */
    public function zuordnen(Team $team, FoodAlchemistPriceImportRun $lauf, array $mapping, ?int $userId = null, bool $merken = true): FoodAlchemistPriceImportRun
    {
        if ((int) $lauf->team_id !== (int) $team->id || $lauf->art !== FoodAlchemistPriceImportRun::ART_PREISLISTE) {
            throw new \RuntimeException('Dieser Lauf gehört nicht zu diesem Team oder ist keine Preisliste.');
        }
        $zuo = app(\Platform\FoodAlchemist\Services\Stammdaten\ImportZuordnungService::class);
        $tmp = $zuo->datei($lauf);
        try {
            $neu = $this->stage($team, $tmp, $mapping, $lauf->supplier_id ? (int) $lauf->supplier_id : null, $userId, $lauf->datei_endung, $lauf->filename, $merken);
        } finally {
            @unlink($tmp);
        }
        if ($neu->status === \Platform\FoodAlchemist\Services\Stammdaten\ImportZuordnungService::STATUS_ZUORDNUNG_NOETIG) {
            $zuo->verwerfen($neu);
            $lauf->update(['mapping' => $neu->mapping]);

            throw new \RuntimeException('Die Zuordnung reicht noch nicht: ' . ($neu->mapping['grund'] ?? 'Pflichtspalten fehlen') . ' — weitere Spalten zuordnen.');
        }
        $zuo->verwerfen($lauf);
        if ($lauf->herkunft === FoodAlchemistPriceImportRun::HERKUNFT_LIEFERANTEN_LINK) {
            $neu->update(['herkunft' => $lauf->herkunft, 'link_id' => $lauf->link_id]);
            $neu = app(LieferantenUploadService::class)->sichere($neu->refresh());
        }

        return $neu;
    }

    /**
     * Schreib-Schicht (Spec 82 §3 / Schritt 4d, Stufe 3): Treffer eines Laufs als TEAMPREISE schreiben.
     *
     * - Schreibt über {@see PriceService::createTeamPrice}: append-only, die Zeile trägt IMMER die team_id des Mandanten, geschlossen wird nur
     *   der eigene Vorgänger desselben Teams. Eine globale Zeile oder ein Listenpreis des Besitzers wird nie angefasst.
     * - **Unveränderter Preis = keine neue Zeile** (gleicher Betrag wie der aktuelle Teampreis des Mandanten, Status Standard-EK).
     * - „gültig ab“: heute oder früher wird geschrieben (der Wert steht in `status_valid_from`); liegt es in der Zukunft, bleibt die Zeile
     *   `zukuenftig` und wird erst bei einem späteren Anwenden geschrieben (das Preismodell kennt keine Preise „ab Datum“ im Voraus).
     * - Idempotent: nur Zeilen im Status treffer/zukuenftig werden angefasst; ein zweites Anwenden schreibt nichts doppelt.
     * - Rechte: nur wer importieren darf ({@see darfImportieren}); der Lauf muss dem Team gehören.
     * - Danach läuft EINMAL die bekannte Post-Import-Kette (betroffene GPs → Rezepte neu rechnen), wie beim Artikel-Import.
     *
     * @return array{angewendet:int, unveraendert:int, zukuenftig:int, fehler:int, kette:array<string,int>, lauf_status:string}
     */
    public function anwenden(Team $team, ?User $user, FoodAlchemistPriceImportRun $run): array
    {
        if (! $this->darfImportieren($team, $user)) {
            throw new FaRechtFehltException(FaRolle::Kuratieren, null, 'Preisliste anwenden (nur das Haupt-Team des Kunden)');
        }
        if ((int) $run->team_id !== (int) $team->id) {
            throw new \RuntimeException('Dieser Lauf gehört einem anderen Team.');
        }
        if ($run->art !== FoodAlchemistPriceImportRun::ART_PREISLISTE) {
            throw new \RuntimeException('Ein Stammdaten-Lauf wird nicht als Preisliste angewendet — die Übernahme je Zeile folgt in der Prüfliste.');
        }

        return $this->schreibeLauf($team, $run, [Zeile::STATUS_TREFFER, Zeile::STATUS_ZUKUENFTIG]);
    }

    /**
     * Täglicher Systemlauf (Kommando `foodalchemist:faellige-preise`): schreibt nur vorgemerkte Preise, deren „Gültig ab“ erreicht ist. Kein Nutzer, keine
     * neue Entscheidung — die Treffer wurden beim Anwenden schon bestätigt; Zeilen mit künftigem Datum bleiben vorgemerkt ({@see wendeZeileAn}).
     *
     * @return array{angewendet:int, unveraendert:int, zukuenftig:int, fehler:int, kette:array<string,int>, lauf_status:string}
     */
    public function faelligeZukunftspreise(Team $team, FoodAlchemistPriceImportRun $run): array
    {
        if ((int) $run->team_id !== (int) $team->id || $run->art !== FoodAlchemistPriceImportRun::ART_PREISLISTE) {
            throw new \RuntimeException('Dieser Lauf gehört nicht zu diesem Team oder ist keine Preisliste.');
        }

        return $this->schreibeLauf($team, $run, [Zeile::STATUS_ZUKUENFTIG]);
    }

    /** @param list<string> $stati  Zeilen-Stati, die angefasst werden */
    private function schreibeLauf(Team $team, FoodAlchemistPriceImportRun $run, array $stati): array
    {
        $heute = Carbon::today();
        $bilanz = ['angewendet' => 0, 'unveraendert' => 0, 'zukuenftig' => 0, 'fehler' => 0];
        $bewegt = [];

        Zeile::where('run_id', $run->id)->where('team_id', $team->id)
            ->whereIn('status', $stati)->orderBy('id')
            ->chunkById(500, function ($zeilen) use ($team, $heute, &$bilanz, &$bewegt) {
                foreach ($zeilen as $z) {
                    $ergebnis = $this->wendeZeileAn($team, $z, $heute);
                    $bilanz[$ergebnis]++;
                    if ($ergebnis === 'angewendet') {
                        $bewegt[(int) $z->supplier_item_id] = true;
                    }
                }
            });

        $offen = Zeile::where('run_id', $run->id)->whereIn('status', [Zeile::STATUS_TREFFER, Zeile::STATUS_ZUKUENFTIG])->exists();
        $counts = (array) $run->counts;
        foreach ([Zeile::STATUS_ANGEWENDET, Zeile::STATUS_UNVERAENDERT, Zeile::STATUS_ZUKUENFTIG, Zeile::STATUS_TREFFER, Zeile::STATUS_FEHLER] as $k) {
            $counts[$k] = Zeile::where('run_id', $run->id)->where('status', $k)->count();
        }
        $run->update(['counts' => $counts, 'status' => $offen ? 'staged' : 'applied']);

        $kette = $bewegt !== [] ? app(FileArticleImportService::class)->postImportKette($team, $bewegt, true) : ['bewegt' => 0, 'gps' => 0, 'rezepte' => 0, 'neu_berechnet' => 0, 'signale' => 0];

        return $bilanz + ['kette' => $kette, 'lauf_status' => (string) $run->status];
    }

    /** @return 'angewendet'|'unveraendert'|'zukuenftig'|'fehler' */
    private function wendeZeileAn(Team $team, Zeile $z, Carbon $heute): string
    {
        $markiere = fn (string $status, ?string $grund = null) => tap($status, fn () => $z->update(['status' => $status, 'reason' => $grund]));

        $gueltigAb = $z->valid_from?->copy()->startOfDay() ?? $heute;
        if ($gueltigAb->gt($heute)) {
            return $markiere(Zeile::STATUS_ZUKUENFTIG, 'Gültig ab ' . $gueltigAb->format('d.m.Y') . ': wird erst ab diesem Tag geschrieben (Preisliste dann erneut anwenden).');
        }
        $item = FoodAlchemistSupplierItem::visibleToTeam($team)->find($z->supplier_item_id);
        if ($item === null) {
            return $markiere(Zeile::STATUS_FEHLER, 'Artikel ist für dieses Team nicht mehr sichtbar (zum Beispiel Stamm-Paket abbestellt).');
        }
        $preis = (float) $z->price;
        if ($preis <= 0) {
            return $markiere(Zeile::STATUS_FEHLER, 'Kein gültiger Preis in der Zeile.');
        }

        // Unverändert? Verglichen wird mit dem aktuellen Teampreis DIESES Teams (nie mit Listen- oder Fremdpreisen).
        $aktuell = $this->preise->scopeAktiv(FoodAlchemistPrice::query())
            ->where('foodalchemist_prices.supplier_item_id', $item->id)->where('foodalchemist_prices.team_id', $team->id)
            ->where(fn ($q) => $q->whereNull('foodalchemist_prices.valid_to')->orWhere('foodalchemist_prices.valid_to', '>=', now()))
            ->orderByRaw('foodalchemist_prices.valid_to IS NULL DESC')->orderByDesc('foodalchemist_prices.id')->first();
        if ($aktuell !== null && (string) $aktuell->status === '0' && abs((float) $aktuell->price - $preis) < 0.00005) {
            return $markiere(Zeile::STATUS_UNVERAENDERT, 'Preis ist schon so hinterlegt, keine neue Zeile.');
        }

        try {
            $neu = $this->preise->createTeamPrice($team, $item, $preis, '0');
            $neu->update(['status_valid_from' => $gueltigAb]);
        } catch (\RuntimeException $e) {
            return $markiere(Zeile::STATUS_FEHLER, $e->getMessage());
        }

        return $markiere(Zeile::STATUS_ANGEWENDET, null);
    }

    /** Die Prüfliste eines Laufs: alles außer Treffer. */
    public function pruefliste(FoodAlchemistPriceImportRun $run): \Illuminate\Support\Collection
    {
        return Zeile::where('run_id', $run->id)->where('team_id', $run->team_id)
            ->whereNotIn('status', [Zeile::STATUS_TREFFER, Zeile::STATUS_ANGEWENDET, Zeile::STATUS_UNVERAENDERT])->orderBy('row_nr')->get();
    }

    // ---- Vorlage zum Herunterladen ----------------------------------------

    /** Kanonische Spalten der Vorlage (Reihenfolge der Oberfläche); jede Überschrift erkennt der eigene Import über SYNONYME ohne Nachzuordnung. */
    public const VORLAGE_KOPF = ['Lieferant', 'Artikelnummer', 'EAN', 'Bezeichnung', 'Gebinde / Einheit', 'Preis netto', 'Gültig ab'];

    /** Eine Beispielzeile (bitte durch eigene Zeilen ersetzen). */
    public const VORLAGE_BEISPIEL = ['Beispiel-Lieferant', 'A-4711', '4000000000017', 'Weizenmehl Type 405, 25 kg Sack', 'Sack 25 kg', '18,90', '01.11.2026'];

    public const VORLAGE_HINWEIS = 'Pflicht je Zeile: Preis (netto) und Artikelnummer ODER EAN. Lieferant, Bezeichnung, Gebinde und „Gültig ab“ sind optional; '
        .'ohne Lieferant-Spalte wählst du den Lieferanten beim Hochladen. Die Beispielzeile bitte durch deine Artikel ersetzen oder löschen. Preise netto in EUR, Datum als TT.MM.JJJJ.';

    /** @return array{spalten: list<string>, beispielzeile: list<string>, hinweis: string, xlsx_verfuegbar: bool} */
    public function vorlage(): array
    {
        return ['spalten' => self::VORLAGE_KOPF, 'beispielzeile' => self::VORLAGE_BEISPIEL, 'hinweis' => self::VORLAGE_HINWEIS, 'xlsx_verfuegbar' => $this->xlsxVerfuegbar()];
    }

    /** Vorlage als CSV-Text (Trennzeichen ;, UTF-8 mit BOM für Excel) — dasselbe Format wie die Prüfliste. */
    public function vorlageCsv(): string
    {
        $fp = fopen('php://temp', 'w+');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, self::VORLAGE_KOPF, ';');
        fputcsv($fp, self::VORLAGE_BEISPIEL, ';');
        rewind($fp);
        $csv = (string) stream_get_contents($fp);
        fclose($fp);

        return $csv;
    }

    /** XLSX nur mit OpenSpout (siehe liesExcel); sonst bleibt nur die CSV-Vorlage. */
    public function xlsxVerfuegbar(): bool
    {
        return class_exists('\OpenSpout\Writer\XLSX\Writer');
    }

    /** Vorlage als XLSX: Blatt 1 = Kopf + Beispielzeile (das liest der Import), Blatt 2 „Hinweise“. */
    public function schreibeVorlageXlsx(string $pfad): void
    {
        if (! $this->xlsxVerfuegbar()) {
            throw new \RuntimeException('Die Excel-Vorlage braucht die Bibliothek openspout/openspout (composer update auf dem Server nötig). Bitte die CSV-Vorlage nehmen.');
        }
        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToFile($pfad);
        $writer->getCurrentSheet()->setName('Preisliste');
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(self::VORLAGE_KOPF));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(self::VORLAGE_BEISPIEL));
        $writer->addNewSheetAndMakeItCurrent();
        $writer->getCurrentSheet()->setName('Hinweise');
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Hinweise']));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues([self::VORLAGE_HINWEIS]));
        $writer->close();
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
    public function lies(string $pfad, ?string $endung = null): array
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
