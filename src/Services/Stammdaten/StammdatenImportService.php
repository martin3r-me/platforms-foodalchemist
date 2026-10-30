<?php

namespace Platform\FoodAlchemist\Services\Stammdaten;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine as Zeile;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun as Lauf;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\FileArticleImportService;
use Platform\FoodAlchemist\Services\PreislistenImportService;
use Platform\FoodAlchemist\Services\SupplierItemService;

/**
 * Stammdaten-Import, Stufe 1a (Kurator 29.10.2026): Vorlage + Einlesen + Abgleich + Prüfliste. Schreibt NICHTS in den Bestand.
 *
 * Eine Pipeline: der Lauf ist der Preislisten-Lauf (`art = stammdaten`), die Spaltenerkennung kommt unverändert aus
 * {@see FileArticleImportService} (FELDER, Preis, Nährwerte, Allergene, Zusatzstoffe — nichts ist hier nachgebaut), und der Abgleich ruft dessen
 * Trockenlauf je Zeile. Zusätzlich liest dieser Dienst vier Spalten selbst, weil der Artikel-Import sie nicht kennt oder anders deutet:
 * „Lieferant“ (eine Datei darf mehrere Lieferanten tragen), „Gültig ab“ (Preis-Beginn; beim Artikel-Import hieße es Aktions-Beginn) und die
 * Haltbarkeit in Tagen. Sie liegen in `daten` unter `x:…` und werden erst bei der Übernahme (Stufe 1b) geschrieben.
 *
 * „Entwurf“ ist die Prüflisten-Zeile: ein neuer Artikel existiert erst, wenn jemand die Zeile übernimmt. Darum gibt es keinen Entwurfsstatus am
 * Artikel und keinen Filter in den Artikel-Abfragen. Aktion je Zeile: neu | aendert | unveraendert | konflikt (geerbter/mehrdeutiger Artikel,
 * Allergen-Rückstufung) | fehler.
 *
 * ⚠ Wie {@see PreislistenImportService::stage}: `stage()` prüft KEINE Berechtigung und vertraut dem Pfad. Aufrufer prüfen
 * {@see PreislistenImportService::darfImportieren}; `$pfad` ist nie eine Benutzereingabe (eigener, vom System erzeugter Temp-Pfad).
 */
class StammdatenImportService
{
    /** Spalten der Vorlage: [Überschrift, Beispiel]. Jede Überschrift erkennt der Import ohne Nachzuordnung (Test: Roundtrip). */
    public const VORLAGE = [
        ['Lieferant', 'Beispiel-Lieferant'],
        ['Artikelnummer', 'A-4711'],
        ['EAN Gebinde', '4000000000017'],
        ['EAN Bestelleinheit', ''],
        ['Bezeichnung', 'Weizenmehl Type 405, 25 kg Sack'],
        ['Marke', 'Beispielmühle'],
        ['Hersteller', 'Beispielmühle GmbH'],
        ['Gebindeeinheit', 'Sack'],
        ['Gebindemenge', '25'],
        ['Einheit', 'kg'],
        ['Nettopreis', '18,90'],
        ['Gültig ab', '01.11.2026'],
        ['Zutatenliste', 'Weizenmehl'],
        ['Herkunftsland', 'Deutschland'],
        ['Haltbarkeit gekühlt Tage', ''],
        ['Haltbarkeit TK Tage', ''],
        ['Haltbarkeit trocken Tage', '365'],
        ['Bio', 'nein'],
        ['Bio-Kontrollnummer', ''],
        ['Allergen Gluten', 'enthalten'], ['Allergen Krebstiere', 'nicht_enthalten'], ['Allergen Eier', 'nicht_enthalten'],
        ['Allergen Fisch', 'nicht_enthalten'], ['Allergen Erdnüsse', 'nicht_enthalten'], ['Allergen Soja', 'nicht_enthalten'],
        ['Allergen Milch', 'nicht_enthalten'], ['Allergen Schalenfrüchte', 'nicht_enthalten'], ['Allergen Sellerie', 'nicht_enthalten'],
        ['Allergen Senf', 'nicht_enthalten'], ['Allergen Sesam', 'nicht_enthalten'], ['Allergen Sulfite', 'spuren'],
        ['Allergen Lupine', 'nicht_enthalten'], ['Allergen Weichtiere', 'nicht_enthalten'],
        ['Zusatzstoff Farbstoff', ''], ['Zusatzstoff Konservierungsstoff', ''], ['Zusatzstoff Antioxidationsmittel', ''],
        ['Zusatzstoff Geschmacksverstärker', ''], ['Zusatzstoff Geschwefelt', ''], ['Zusatzstoff Geschwärzt', ''], ['Zusatzstoff Gewachst', ''],
        ['Zusatzstoff Phosphat', ''], ['Zusatzstoff Süßungsmittel', ''], ['Zusatzstoff Phenylalanin', ''], ['Zusatzstoff Abführend', ''],
        ['Zusatzstoff Schutzatmosphäre', ''], ['Zusatzstoff Koffein', ''], ['Zusatzstoff Milcheiweiß', ''], ['Zusatzstoff Chinin', ''],
        ['Zusatzstoff Taurin', ''], ['Zusatzstoff Aufmerksamkeit Kinder', ''], ['Zusatzstoff Zuckerarten und Süßungsmittel', ''],
        ['Nährwert kcal', '350'], ['Nährwert kJ', '1480'], ['Nährwert Fett', '1,2'], ['Nährwert davon gesättigte Fettsäuren', '0,2'],
        ['Nährwert Kohlenhydrate', '72'], ['Nährwert davon Zucker', '1,0'], ['Nährwert Eiweiß', '10'], ['Nährwert Salz', '0,01'],
    ];

    public const HINWEIS = 'Pflicht je Zeile: Bezeichnung (bei neuen Artikeln) und Artikelnummer ODER EAN. Leere Zellen heißen „steht nicht in der Datei“ und ändern nichts. '
        . 'Allergene: enthalten | spuren | nicht_enthalten | unbekannt (leer = keine Aussage, wird nie als „nicht enthalten“ gelesen). Zusatzstoffe: ja | nein | unbekannt. '
        . 'Nährwerte je 100 g/ml (Salz in g, Natrium in mg). Preis netto in EUR, Datum als TT.MM.JJJJ. Nichts geht ungeprüft in den Bestand: jede Zeile landet in der Prüfliste.';

    /** Spalten, die dieser Dienst selbst liest (normalisierte Überschrift → Schlüssel in `daten`). */
    private const EIGENE = [
        'x:lieferant' => ['lieferant', 'lieferantname', 'lieferantenname', 'supplier'],
        'x:gueltig_ab' => ['gueltigab', 'gueltigvon', 'preisgueltigab', 'validfrom'],
        'x:haltbar_gekuehlt' => ['haltbarkeitgekuehlttage', 'haltbarkeitgekuehlt'],
        'x:haltbar_tk' => ['haltbarkeittktage', 'haltbarkeittk', 'haltbarkeittiefkuehltage'],
        'x:haltbar_trocken' => ['haltbarkeittrockentage', 'haltbarkeittrocken'],
    ];

    public function __construct(
        private readonly FileArticleImportService $artikel,
        private readonly PreislistenImportService $liste,
        private readonly SupplierItemService $items,
        private readonly NaehrwertPruefer $pruefer,
    ) {}

    /** @return array{spalten: list<string>, beispielzeile: list<string>, hinweis: string, xlsx_verfuegbar: bool, art: string} */
    public function vorlage(): array
    {
        return ['art' => Lauf::ART_STAMMDATEN, 'spalten' => array_column(self::VORLAGE, 0), 'beispielzeile' => array_column(self::VORLAGE, 1),
            'hinweis' => self::HINWEIS, 'xlsx_verfuegbar' => $this->liste->xlsxVerfuegbar()];
    }

    /** Vorlage als CSV (Trennzeichen ;, UTF-8 mit BOM für Excel). */
    public function vorlageCsv(): string
    {
        $fp = fopen('php://temp', 'w+');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, array_column(self::VORLAGE, 0), ';');
        fputcsv($fp, array_column(self::VORLAGE, 1), ';');
        rewind($fp);
        $csv = (string) stream_get_contents($fp);
        fclose($fp);

        return $csv;
    }

    /** Vorlage als XLSX: Blatt „Stammdaten“ (Kopf + Beispielzeile, das liest der Import) und Blatt „Hinweise“. */
    public function schreibeVorlageXlsx(string $pfad): void
    {
        if (! $this->liste->xlsxVerfuegbar()) {
            throw new \RuntimeException('Die Excel-Vorlage braucht die Bibliothek openspout/openspout (composer update auf dem Server nötig). Bitte die CSV-Vorlage nehmen.');
        }
        $writer = new \OpenSpout\Writer\XLSX\Writer();
        $writer->openToFile($pfad);
        $writer->getCurrentSheet()->setName('Stammdaten');
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(array_column(self::VORLAGE, 0)));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(array_column(self::VORLAGE, 1)));
        $writer->addNewSheetAndMakeItCurrent();
        $writer->getCurrentSheet()->setName('Hinweise');
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Hinweise']));
        $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues([self::HINWEIS]));
        $writer->close();
    }

    /**
     * Datei lesen, je Zeile gegen den Bestand prüfen, als Lauf ablegen. Schreibt nur Lauf und Zeilen.
     */
    public function stage(Team $team, string $pfad, ?int $supplierId = null, ?int $userId = null, ?string $endung = null, ?string $dateiname = null, string $herkunft = 'user', ?int $linkId = null, array $mapping = [], bool $merken = true): Lauf
    {
        $datei = $this->liste->lies($pfad, $endung);
        $zuo = app(ImportZuordnungService::class);
        $originalKopf = $datei['kopf'];

        $standard = null;
        if ($supplierId !== null) {
            $standard = FoodAlchemistSupplier::visibleToTeam($team)->whereKey($supplierId)->first()
                ?? throw new \RuntimeException('Lieferant nicht in der Team-Kette sichtbar (supplier prüfen).');
        }
        $lieferanten = FoodAlchemistSupplier::visibleToTeam($team)->get(['id', 'name'])->groupBy(fn ($s) => $this->norm((string) $s->name));

        // Zuordnung: ausdrücklich (Oberfläche/MCP) gewinnt, sonst die gemerkte des Lieferanten. Sie schreibt die Kopfzeile auf die Vorlagen-Namen um,
        // danach läuft derselbe Leser wie ohne Zuordnung (keine zweite Spaltenerkennung).
        $spalten = $mapping !== [] ? $zuo->ausFeldMapping($originalKopf, $mapping) : [];
        $merkId = $standard?->id ?? $this->einzigerLieferant($originalKopf, $datei['zeilen'], $lieferanten);
        $kopfHinweise = [];
        if ($spalten === [] && $merkId !== null && ($gemerkt = $zuo->laden($team, $merkId, Lauf::ART_STAMMDATEN)) !== null) {
            $angewendet = $zuo->anwenden($gemerkt, $originalKopf);
            $spalten = $angewendet['spalten'];
            $kopfHinweise = $angewendet['hinweise'];
        }
        $datei['kopf'] = $this->umbenennen($originalKopf, $spalten);
        $eigene = $this->eigeneSpalten($datei['kopf']);

        if ($standard === null && ! isset($eigene['x:lieferant'])) {
            throw new \RuntimeException('Die Datei hat keine Lieferant-Spalte: Standard-Lieferant angeben (supplier).');
        }

        // Kopf einmal prüfen (Pflichtspalten, Doppelbelegung). Fehlt etwas, ist das kein Fehler: der Lauf wartet auf die Zuordnung.
        try {
            $this->lesen($datei['kopf'], [], $eigene);
        } catch (\Platform\FoodAlchemist\Exceptions\SpaltenZuordnungFehltException $e) {
            return $zuo->zuordnungNoetig($team, Lauf::ART_STAMMDATEN, $pfad, $endung, $dateiname, $standard?->id ?? $merkId, $userId, $herkunft, $linkId,
                ['kopf' => $originalKopf, 'zeilen' => $datei['zeilen']], $e->getMessage(), $spalten);
        }

        // Zeilen nach Lieferant gruppieren; Lieferant unbekannt/mehrdeutig ⇒ Zeile mit Fehler, nie geraten.
        $gruppen = [];
        $ohneLieferant = [];
        foreach ($datei['zeilen'] as $nr => $zellen) {
            $name = isset($eigene['x:lieferant']) ? trim((string) ($zellen[$eigene['x:lieferant']] ?? '')) : '';
            if ($name === '' && $standard !== null) {
                $gruppen[(int) $standard->id][$nr] = $zellen;
            } elseif ($name === '') {
                $ohneLieferant[$nr] = ['name' => '', 'grund' => 'Zeile ohne Lieferant und kein Standard-Lieferant gewählt.', 'zellen' => $zellen];
            } else {
                $treffer = $lieferanten->get($this->norm($name), collect());
                if ($treffer->count() === 1) {
                    $gruppen[(int) $treffer->first()->id][$nr] = $zellen;
                } else {
                    $ohneLieferant[$nr] = ['name' => $name, 'grund' => $treffer->isEmpty() ? "Lieferant „{$name}“ ist nicht bekannt (oder für dieses Team nicht sichtbar)." : "Lieferant „{$name}“ ist mehrdeutig.", 'zellen' => $zellen];
                }
            }
        }

        if ($mapping !== [] && $merken && $merkId !== null) {
            $zuo->speichern($team, $merkId, Lauf::ART_STAMMDATEN, $originalKopf, $spalten);
        }

        return DB::transaction(function () use ($team, $pfad, $dateiname, $datei, $eigene, $standard, $gruppen, $ohneLieferant, $userId, $herkunft, $linkId, $spalten, $kopfHinweise) {
            $lauf = Lauf::create([
                'team_id' => $team->id, 'supplier_id' => $standard?->id, 'filename' => basename($dateiname ?? $pfad), 'status' => 'staged',
                'art' => Lauf::ART_STAMMDATEN, 'herkunft' => $herkunft, 'link_id' => $linkId, 'mapping' => ['eigene' => $eigene, 'spalten' => $spalten], 'created_by' => $userId,
            ]);
            $zaehler = ['zeilen' => 0, 'neu' => 0, 'aendert' => 0, 'unveraendert' => 0, 'konflikt' => 0, 'fehler' => 0];
            $hinweise = $kopfHinweise;
            $zeilen = [];

            foreach ($gruppen as $sid => $rows) {
                $gelesen = $this->lesen($datei['kopf'], $rows, $eigene);
                $hinweise = array_values(array_unique(array_merge($hinweise, $gelesen['hinweise'])));
                foreach ($gelesen['zeilen'] as $nr => $z) {
                    $zeilen[$nr] = $this->pruefeZeile($team, (int) $sid, $z['werte'], $z['eigene']);
                }
            }
            foreach ($ohneLieferant as $nr => $o) {
                $zeilen[$nr] = ['status' => 'lieferant_unbekannt', 'aktion' => Zeile::AKTION_FEHLER, 'supplier_name' => $o['name'], 'reason' => $o['grund'],
                    'daten' => $this->rohDaten($datei['kopf'], $o['zellen']), 'hinweise' => []];
            }
            ksort($zeilen);
            foreach ($zeilen as $nr => $z) {
                Zeile::create(['team_id' => $team->id, 'run_id' => $lauf->id, 'row_nr' => $nr] + $z);
                $zaehler['zeilen']++;
                $zaehler[$z['aktion']]++;
            }
            $lauf->update(['counts' => $zaehler + ['hinweise' => $hinweise]]);

            return $lauf->refresh();
        });
    }

    /**
     * Prüfliste eines Laufs, optional nach Aktion gefiltert (neu | aendert | konflikt | fehler | unveraendert). Strikt team-eigen.
     *
     * @return \Illuminate\Support\Collection<int, Zeile>
     */
    public function pruefliste(Lauf $lauf, ?string $aktion = null): \Illuminate\Support\Collection
    {
        return Zeile::where('run_id', $lauf->id)->where('team_id', $lauf->team_id)
            ->when($aktion !== null, fn ($q) => $q->where('aktion', $aktion))->orderBy('row_nr')->get();
    }

    /**
     * „Zuordnung nötig“ abschließen: aus der abgelegten Datei mit der gewählten Zuordnung neu prüfen. Der wartende Lauf wird danach entfernt.
     *
     * @param  array<string, string|int>  $mapping  Zielfeld (Vorlagen-Überschrift) ⇒ Überschrift|Spaltenindex
     */
    public function zuordnen(Team $team, Lauf $lauf, array $mapping, ?int $userId = null, bool $merken = true): Lauf
    {
        if ((int) $lauf->team_id !== (int) $team->id || $lauf->art !== Lauf::ART_STAMMDATEN) {
            throw new \RuntimeException('Dieser Lauf gehört nicht zu diesem Team oder ist kein Stammdaten-Lauf.');
        }
        $zuo = app(ImportZuordnungService::class);
        $tmp = $zuo->datei($lauf);
        try {
            $neu = $this->stage($team, $tmp, $lauf->supplier_id ? (int) $lauf->supplier_id : null, $userId, $lauf->datei_endung, $lauf->filename, $lauf->herkunft, $lauf->link_id, $mapping, $merken);
        } finally {
            @unlink($tmp);
        }
        if ($neu->status === ImportZuordnungService::STATUS_ZUORDNUNG_NOETIG) {
            $zuo->verwerfen($neu);   // gleiche Datei liegt schon beim wartenden Lauf; kein zweites Exemplar
            $lauf->update(['mapping' => $neu->mapping]);

            throw new \RuntimeException('Die Zuordnung reicht noch nicht: ' . ($neu->mapping['grund'] ?? 'Pflichtspalten fehlen') . ' — weitere Spalten zuordnen.');
        }
        $zuo->verwerfen($lauf);

        return app(\Platform\FoodAlchemist\Services\LieferantenUploadService::class)->sichere($neu);
    }

    /**
     * Eine gespeicherte Zeile gegen den AKTUELLEN Bestand neu prüfen (Übernahme-Guard, 1b): ist der Bestand seit der Prüfung anders, weicht das Ergebnis ab.
     *
     * @return array<string, mixed>  wie pruefeZeile (aktion, diff, hinweise, …)
     */
    public function pruefeGespeichert(Team $team, Zeile $z): array
    {
        $daten = (array) $z->daten;
        $werte = array_filter($daten, fn ($k) => ! str_starts_with((string) $k, 'x:'), ARRAY_FILTER_USE_KEY);
        $eigene = array_filter($daten, fn ($k) => str_starts_with((string) $k, 'x:'), ARRAY_FILTER_USE_KEY);

        return $this->pruefeZeile($team, (int) $z->supplier_id, $werte, $eigene);
    }

    // ---- intern -----------------------------------------------------------

    /** Kontext für die Nährwert-Prüfung: Artikelbezeichnung, bei bekanntem Artikel Warengruppe/Unterkategorie seines GP. @return array{wg?: ?string, sub?: ?string, bezeichnung?: ?string} */
    private function nwKontext(?FoodAlchemistSupplierItem $item, array $w): array
    {
        $k = ['bezeichnung' => (string) ($item?->designation ?? ($w['bezeichnung'] ?? ''))];
        if ($item !== null) {
            $gp = DB::table('foodalchemist_supplier_item_structures as s')->join('foodalchemist_gps as g', 'g.id', '=', 's.gp_id')
                ->where('s.supplier_item_id', $item->id)->whereNull('s.deleted_at')->first(['g.commodity_group_code', 'g.name']);
            if ($gp !== null) {
                $k['wg'] = (string) $gp->commodity_group_code;
                $k['name'] = (string) $gp->name;
            }
        }

        return $k;
    }

    /** Kopfzeile mit den zugeordneten Spalten auf die Vorlagen-Namen umschreiben (Index ⇒ Zielfeld). @param list<string> $kopf @param array<int, string> $spalten @return list<string> */
    private function umbenennen(array $kopf, array $spalten): array
    {
        foreach ($spalten as $i => $ziel) {
            if (isset($kopf[$i])) {
                $kopf[$i] = $ziel;
            }
        }

        return $kopf;
    }

    /** Genau ein (bekannter, sichtbarer) Lieferant in der Lieferant-Spalte ⇒ seine ID; sonst null. */
    private function einzigerLieferant(array $kopf, array $zeilen, \Illuminate\Support\Collection $lieferanten): ?int
    {
        $idx = $this->eigeneSpalten($kopf)['x:lieferant'] ?? null;
        if ($idx === null) {
            return null;
        }
        $namen = collect($zeilen)->map(fn ($z) => $this->norm((string) ($z[$idx] ?? '')))->filter()->unique();
        if ($namen->count() !== 1) {
            return null;
        }
        $treffer = $lieferanten->get($namen->first(), collect());

        return $treffer->count() === 1 ? (int) $treffer->first()->id : null;
    }

    /**
     * Zeile gegen den Bestand prüfen (Trockenlauf des Artikel-Imports) und das Ergebnis als Zeilen-Daten liefern.
     *
     * @param  array<string, string>  $werte   kanonische Werte aus dem Artikel-Import-Leser
     * @param  array<string, string>  $eigene  vom Dienst gelesene Spalten (x:…)
     * @return array<string, mixed>
     */
    private function pruefeZeile(Team $team, int $supplierId, array $werte, array $eigene): array
    {
        $bewegt = [];
        $befund = $this->artikel->verarbeiteZeile($team, $supplierId, ['nr' => 0, 'werte' => $werte], false, $bewegt);
        $lieferant = FoodAlchemistSupplier::whereKey($supplierId)->value('name');
        $artno = ($werte['artikelnummer'] ?? '') !== '' ? $werte['artikelnummer'] : null;
        $eans = array_values(array_filter([$werte['eangebinde'] ?? '', $werte['eanbestelleinheit'] ?? '']));

        $item = null;
        try {
            $art = null;
            $item = $artno !== null || $eans !== [] ? $this->artikel->finde($team, $supplierId, $artno, $eans, $art) : null;
        } catch (\RuntimeException) {
            $art = null;
        }

        $hinweise = array_map(fn ($t) => ['code' => 'warnung', 'stufe' => 'warnung', 'text' => $t], (array) ($befund['warnungen'] ?? []));
        $diff = $this->diff($team, $item, $werte, $hinweise);
        $aktion = $this->aktion($befund, $diff, $hinweise);

        $gueltig = null;
        if (($eigene['x:gueltig_ab'] ?? '') !== '') {
            $gueltig = $this->datum($eigene['x:gueltig_ab']);
            if ($gueltig === null) {
                $hinweise[] = ['code' => 'gueltig_ab', 'stufe' => 'warnung', 'text' => "Gültig ab [{$eigene['x:gueltig_ab']}] ist kein Datum — wird ignoriert."];
            } elseif ($gueltig->isFuture()) {
                $hinweise[] = ['code' => 'gueltig_ab_zukunft', 'stufe' => 'info', 'text' => 'Preis gilt erst ab ' . $gueltig->format('d.m.Y') . ' — wird bei der Übernahme vorgemerkt.'];
            }
        }
        foreach (['x:haltbar_gekuehlt', 'x:haltbar_tk', 'x:haltbar_trocken'] as $k) {
            if (($eigene[$k] ?? '') !== '' && ! preg_match('/^\d{1,5}$/', trim($eigene[$k]))) {
                $hinweise[] = ['code' => 'haltbarkeit', 'stufe' => 'warnung', 'text' => "Haltbarkeit [{$eigene[$k]}] ist keine ganze Zahl in Tagen — wird ignoriert."];
            }
        }

        return [
            'status' => Zeile::STATUS_GEPRUEFT, 'aktion' => $aktion, 'supplier_id' => $supplierId, 'supplier_name' => $lieferant,
            'article_number' => $artno !== null ? mb_substr($artno, 0, 64) : null, 'ean' => ($eans[0] ?? null) !== null ? mb_substr($eans[0], 0, 32) : null,
            'designation' => ($werte['bezeichnung'] ?? '') !== '' ? mb_substr($werte['bezeichnung'], 0, 255) : null,
            'unit' => ($werte['gebindeeinheit'] ?? '') !== '' ? mb_substr($werte['gebindeeinheit'], 0, 64) : null,
            'price' => $diff['preis']['neu'] ?? null, 'valid_from' => $gueltig?->toDateString(),
            'match_method' => $art, 'supplier_item_id' => $item?->id, 'reason' => $befund['grund'] ?? null,
            'daten' => $werte + array_filter($eigene, fn ($v) => $v !== ''), 'diff' => $diff === [] ? null : $diff, 'hinweise' => $hinweise,
        ];
    }

    /**
     * Soll/Ist je Feld/Block für die Prüfliste: Artikelfelder, Preis, Nährwerte, Allergene, Zusatzstoffe. Füllt Hinweise (Allergen-Rückstufung,
     * Nährwert-Prüfung). Ein neuer Artikel hat kein „alt“.
     *
     * @param  array<string, string>  $w
     * @param  list<array<string, string>>  $hinweise
     * @return array<string, mixed>
     */
    private function diff(Team $team, ?FoodAlchemistSupplierItem $item, array $w, array &$hinweise): array
    {
        $diff = [];
        $warn = [];
        $werte = $this->artikel->mappeWerte($w, $warn);
        foreach ($werte as $spalte => $neu) {
            $alt = $item?->getAttribute($spalte);
            if ($item !== null && $this->gleich($alt, $neu)) {
                continue;
            }
            $diff['felder'][$spalte] = ['alt' => $alt, 'neu' => $neu];
        }

        $nw = $this->artikel->detailWerte($w, 'nw', $warn);
        $ist = $item !== null ? $this->items->getNutrition($item) : [];
        foreach ($nw as $k => $v) {
            $alt = ($ist[$k] ?? '') === '' ? null : (float) $ist[$k];
            if ($alt === null || abs($alt - (float) $v) >= 0.0005) {
                $diff['naehrwerte'][$k] = ['alt' => $alt, 'neu' => $v];
            }
        }
        foreach ($this->pruefer->pruefe($nw, $this->nwKontext($item, $w)) as $h) {
            $hinweise[] = ['code' => $h['regel'], 'bereich' => 'naehrwert', 'stufe' => match ($h['schwere']) { 'hart' => 'konflikt', 'umrechnung' => 'info', default => 'warnung' }, 'text' => 'Nährwert: ' . $h['text'],
                'feld' => $h['feld'], 'wert' => $h['wert'], 'erwartet' => $h['erwartet'], 'vorschlag' => $h['vorschlag']];
        }

        foreach ([['al', 'allergene', 'getAllergens'], ['dk', 'zusatzstoffe', 'getDeclarations']] as [$kuerzel, $block, $lesen]) {
            $neu = $this->artikel->detailWerte($w, $kuerzel, $warn);
            $istBlock = $item !== null ? $this->items->{$lesen}($item) : [];
            foreach ($neu as $k => $v) {
                $alt = $istBlock[$k] ?? 'unbekannt';
                if ($alt === $v) {
                    continue;
                }
                $diff[$block][$k] = ['alt' => $alt, 'neu' => $v];
                if ($kuerzel === 'al' && $alt === 'enthalten' && $v !== 'enthalten') {
                    $hinweise[] = ['code' => 'allergen_rueckstufung', 'bereich' => 'allergene', 'stufe' => 'konflikt', 'text' => "Allergen {$k}: bisher „enthalten“, die Datei sagt „{$v}“ — Rückstufung nur mit Beleg."];
                }
            }
        }

        if (($w['preis'] ?? '') !== '' && ($p = $this->preisWert($w['preis'])) !== null) {
            $aktiv = $item !== null ? app(\Platform\FoodAlchemist\Services\PriceService::class)->activeFor($item->id, $team) : null;
            $alt = $aktiv?->price !== null ? (float) $aktiv->price : null;
            if ($alt === null || abs($alt - $p) >= 0.0005) {
                $diff['preis'] = ['alt' => $alt, 'neu' => $p];
            }
        } elseif (($w['preis'] ?? '') !== '') {
            $hinweise[] = ['code' => 'preis_fehler', 'stufe' => 'fehler', 'text' => "Preis [{$w['preis']}] ist keine gültige positive Zahl."];
        }

        return $diff;
    }

    /** @param array<string, mixed> $befund */
    private function aktion(array $befund, array $diff, array $hinweise): string
    {
        $status = (string) ($befund['status'] ?? '');
        if ($status === 'fehler') {
            return str_contains((string) ($befund['grund'] ?? ''), 'mehrere') ? Zeile::AKTION_KONFLIKT : Zeile::AKTION_FEHLER;
        }
        if ($status === 'uebersprungen') {
            return Zeile::AKTION_KONFLIKT;
        }
        if (collect($hinweise)->contains(fn ($h) => $h['stufe'] === 'fehler')) {
            return Zeile::AKTION_FEHLER;
        }
        if (collect($hinweise)->contains(fn ($h) => $h['stufe'] === 'konflikt')) {
            return Zeile::AKTION_KONFLIKT;
        }
        if ($status === 'neu') {
            return Zeile::AKTION_NEU;
        }

        return $diff === [] ? Zeile::AKTION_UNVERAENDERT : Zeile::AKTION_AENDERT;
    }

    /**
     * Kopf + Zeilen einer Lieferanten-Gruppe über den Artikel-Import-Leser einlesen (er erkennt die Spalten). Die selbst gelesenen Spalten
     * werden vorher herausgenommen, die Zeilen über eine Temp-CSV im System-Temp-Pfad durchgereicht und wieder auf die Dateizeile gemappt.
     *
     * @param  list<string>  $kopf
     * @param  array<int, list<string>>  $zeilen  Dateizeile ⇒ Zellen
     * @param  array<string, int>  $eigene  Schlüssel ⇒ Spaltenindex
     * @return array{zeilen: array<int, array{werte: array<string, string>, eigene: array<string, string>}>, hinweise: list<string>}
     */
    private function lesen(array $kopf, array $zeilen, array $eigene): array
    {
        $rest = array_values(array_diff_key($kopf, array_flip($eigene)));
        $tmp = tempnam(sys_get_temp_dir(), 'fa-stamm-');
        $csv = $tmp . '.csv';
        rename($tmp, $csv);
        try {
            $fp = fopen($csv, 'w');
            fputcsv($fp, $rest, ';');
            $nummern = [];
            foreach ($zeilen as $nr => $zellen) {
                $zeile = [];
                foreach ($zellen as $i => $z) {
                    if (! in_array($i, $eigene, true)) {
                        $zeile[] = str_replace(["\r", "\n"], ' ', (string) $z);
                    }
                }
                fputcsv($fp, $zeile, ';');
                $nummern[] = $nr;
            }
            fclose($fp);
            $gelesen = $this->artikel->liesDatei($csv);
        } finally {
            @unlink($csv);
        }

        $out = [];
        foreach ($gelesen['zeilen'] as $z) {
            $nr = $nummern[$z['nr'] - 2] ?? null;   // Kopf = Zeile 1
            if ($nr === null) {
                continue;
            }
            $x = [];
            foreach ($eigene as $key => $idx) {
                $x[$key] = trim((string) ($zeilen[$nr][$idx] ?? ''));
            }
            $out[$nr] = ['werte' => $z['werte'], 'eigene' => $x];
        }

        return ['zeilen' => $out, 'hinweise' => $gelesen['hinweise']];
    }

    /** @param list<string> $kopf @return array<string, int> */
    private function eigeneSpalten(array $kopf): array
    {
        $out = [];
        foreach ($kopf as $i => $titel) {
            $n = $this->norm((string) $titel);
            foreach (self::EIGENE as $key => $aliase) {
                if (in_array($n, $aliase, true)) {
                    if (isset($out[$key])) {
                        throw new \RuntimeException("Spalte [{$titel}] doppelt belegt — Vorlage bereinigen.");
                    }
                    $out[$key] = $i;
                }
            }
        }

        return $out;
    }

    /** @param list<string> $kopf @param list<string> $zellen @return array<string, string> */
    private function rohDaten(array $kopf, array $zellen): array
    {
        $out = [];
        foreach ($kopf as $i => $titel) {
            if (($z = trim((string) ($zellen[$i] ?? ''))) !== '') {
                $out[(string) $titel] = $z;
            }
        }

        return $out;
    }

    private function gleich(mixed $alt, mixed $neu): bool
    {
        if ($alt === null) {
            return false;
        }
        if (is_bool($neu)) {
            return (bool) $alt === $neu;
        }
        if (is_int($neu) || is_float($neu)) {
            return abs((float) $alt - (float) $neu) < 0.0005;
        }

        return trim((string) $alt) === trim((string) $neu);
    }

    private function preisWert(string $roh): ?float
    {
        $s = str_replace([' ', '€'], '', $roh);
        $s = str_contains($s, ',') ? str_replace(',', '.', str_replace('.', '', $s)) : $s;

        return is_numeric($s) && (float) $s > 0 ? (float) $s : null;
    }

    private function datum(string $roh): ?Carbon
    {
        if (preg_match('/^\d{5}$/', trim($roh)) && (int) $roh > 20000 && (int) $roh < 80000) {   // Excel-Seriennummer (Zelle ohne Datumsformat)
            return Carbon::create(1899, 12, 30)->addDays((int) $roh);
        }
        foreach (['d.m.Y', 'Y-m-d', 'd.m.y'] as $format) {
            try {
                $d = Carbon::createFromFormat('!' . $format, trim($roh));
                if ($d !== false && $d->format($format) === trim($roh)) {
                    return $d;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function norm(string $s): string
    {
        $t = mb_strtolower(trim($s));
        $t = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $t);

        return (string) preg_replace('/[^a-z0-9]/', '', $t);
    }
}
