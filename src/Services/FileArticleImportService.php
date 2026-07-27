<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * Spec 13 · S1a (Kanal B) — der Datei-Import für Lieferanten-Artikel.
 *
 * FA ist Master (Spec 13 §0): das hier ist eine reine EINGANGS-Schnittstelle, es
 * gibt keinen Rückweg nach draußen. Eine Datei = ein Lieferant = eine Zeile je
 * Artikel (E1); der Upsert-Schlüssel ist `(supplier_id, article_number)` mit EAN
 * als Fallback (E2) — bewusst NICHT `legacy_id`, das ist Necta-Erbe.
 *
 * Was diese Stufe schreibt: den Artikel-Stamm (`foodalchemist_supplier_items`).
 * Was sie NOCH NICHT schreibt: Preis (+ Post-Import-Kette E4), Nährwerte,
 * Allergene, Deklarationen, Lieferbedingungen. Diese Spalten werden in der Datei
 * **erkannt und namentlich gemeldet** statt still verschluckt — ein Import, der
 * eine Preis-Spalte kommentarlos ignoriert, wäre die stille Drift, die E4 gerade
 * verhindern soll.
 *
 * Drei tragende Regeln:
 *  1. **Leere Zelle heißt „steht nicht in der Datei", nicht „lösche den Wert".**
 *     Nur Spalten, die die Datei mitbringt UND befüllt hat, werden geschrieben.
 *     Damit ist ein zweiter Lauf derselben Datei per Konstruktion ein No-op
 *     (Idempotenz, DoD-Hygiene) und ein Teil-Katalog überschreibt keine Pflege.
 *  2. **D1 gilt auch beim Import.** Trifft die Zeile einen geerbten Artikel des
 *     Eltern-Teams, wird er NICHT verändert — die Zeile wird mit Grund
 *     übersprungen. Eine team-lokale Kopie anzulegen wäre der stille Doppel-
 *     Katalog; ein Update wäre ein Schreibzugriff auf fremdes Eigentum.
 *  3. **Mehrdeutigkeit ist ein Fehler, keine Wahl.** Treffen Artikelnummer oder
 *     EAN mehr als einen Bestandsartikel, wird die Zeile abgelehnt statt geraten
 *     (es gibt heute keinen Unique-Index auf `(supplier_id, article_number)` —
 *     der aus E2 ist eine eigene Migration, s. Fahrplan).
 */
class FileArticleImportService
{
    /** Zeilen-Obergrenze je Lauf — schützt vor versehentlich geladenen Voll-Katalogen. */
    public const MAX_ZEILEN = 20000;

    /**
     * Spalten-Vorlage (E1): kanonischer Name => [Ziel-Spalte, Typ, Alias-Liste].
     *
     * Header-Erkennung läuft über {@see normHeader} — Groß/Klein, Umlaute,
     * Punkte, Bindestriche und Leerzeichen sind egal. Die Alias-Listen enthalten
     * deshalb nur echte Synonyme, keine Schreibvarianten.
     *
     * Sprach-Grenze (README, Schema-Sprache Englisch): die Ziel-Spalten sind
     * englisch, die Datei-Header deutsch — sie kommen von einem Menschen beim
     * Lieferanten. Die Übersetzung steht genau hier und nirgends sonst.
     */
    public const FELDER = [
        'artikelnummer' => ['article_number', 'string', ['artikelnr', 'artnr', 'artikelnummer', 'articlenumber', 'artikelnummerlieferant']],
        'bezeichnung' => ['designation', 'string', ['bezeichnung', 'artikelbezeichnung', 'designation', 'name', 'artikelname']],
        'marketingname' => ['marketing_name', 'string', ['marketingname', 'handelsname', 'marketingbezeichnung']],
        'verkehrsbezeichnung' => ['regulated_name', 'string', ['verkehrsbezeichnung', 'rechtlichebezeichnung']],
        'marke' => ['brand', 'string', ['marke', 'brand']],
        'hersteller' => ['manufacturer', 'string', ['hersteller', 'manufacturer', 'produzent']],
        'herkunft' => ['origin', 'string', ['herkunft', 'origin', 'ursprung']],
        'herkunftsland' => ['origin_country', 'string', ['herkunftsland', 'ursprungsland', 'origincountry']],
        'gebindeeinheit' => ['packaging_unit', 'string', ['gebindeeinheit', 'verpackungseinheit', 'packagingunit', 've']],
        'bestelleinheit' => ['ordering_unit', 'string', ['bestelleinheit', 'orderingunit', 'be']],
        'gebindejebestelleinheit' => ['qty_ordering_per_packaging', 'decimal', ['gebindejebestelleinheit', 'vejebe', 'gebindeprobestelleinheit']],
        'gebindemenge' => ['qty', 'decimal', ['gebindemenge', 'inhalt', 'menge', 'fuellmenge', 'qty']],
        'einheit' => ['unit_code', 'unit', ['einheit', 'kalkulationseinheit', 'unit', 'unitcode', 'mengeneinheit']],
        'eangebinde' => ['ean_packaging', 'string', ['eangebinde', 'ean', 'eanve', 'gtin', 'eanpackaging']],
        'eanbestelleinheit' => ['ean_ordering', 'string', ['eanbestelleinheit', 'eanbe', 'eanordering']],
        'mwst' => ['vat', 'decimal', ['mwst', 'ust', 'umsatzsteuer', 'steuersatz', 'vat']],
        'bio' => ['is_organic', 'bool', ['bio', 'oeko', 'organic', 'isorganic']],
        'biokontrollnummer' => ['organic_control_number', 'string', ['biokontrollnummer', 'oekokontrollnummer', 'kontrollstellennummer']],
        'vegan' => ['is_vegan', 'bool', ['vegan', 'isvegan']],
        'vegetarisch' => ['is_vegetarian', 'bool', ['vegetarisch', 'vegetarian', 'isvegetarian']],
        'alkohol' => ['is_alcohol', 'bool', ['alkohol', 'alkoholhaltig', 'isalcohol']],
        'halal' => ['is_halal', 'bool', ['halal', 'ishalal']],
        'gvofrei' => ['is_gmo_free', 'bool', ['gvofrei', 'gentechnikfrei', 'gmofree', 'ohnegentechnik']],
        'vorbestellung' => ['is_preorder', 'bool', ['vorbestellung', 'vorbestellpflichtig', 'preorder']],
        'vorbestelltage' => ['preorder_days', 'int', ['vorbestelltage', 'vorlaufzeittage', 'preorderdays', 'vorbestellzeit']],
        'ausgelistet' => ['is_discontinued', 'bool', ['ausgelistet', 'eingestellt', 'discontinued', 'inaktiv']],
        'zutatenliste' => ['ingredients_supplier', 'string', ['zutatenliste', 'zutaten', 'ingredients']],
        'zusatztext' => ['additional_text', 'string', ['zusatztext', 'bemerkung', 'hinweis', 'additionaltext']],
    ];

    /** Erlaubte Kalkulationseinheiten — dieselbe Liste wie bei der Hand-Anlage (GL-11). */
    public const UNIT_CODES = SupplierItemService::UNIT_CODES;

    /**
     * Spalten, die zur Vorlage gehören, aber erst eine spätere Stufe schreibt.
     * Sie werden erkannt und im Bericht namentlich genannt (nie still ignoriert).
     *
     * @var array<string, string>
     */
    public const SPAETER = [
        'preis' => 'S1b (Preis-Write + Recompute-Kette E4)',
        'ekpreis' => 'S1b (Preis-Write + Recompute-Kette E4)',
        'preisstatus' => 'S1b (Preis-Write + Recompute-Kette E4)',
        'preisnotiz' => 'S1b (Preis-Write + Recompute-Kette E4)',
        'mindestbestellwert' => 'S2 (Lieferbedingungen am Lieferanten, E3)',
        'freihausab' => 'S2 (Lieferbedingungen am Lieferanten, E3)',
        'zahlungsziel' => 'S2 (Lieferbedingungen am Lieferanten, E3)',
        'rueckverguetung' => 'S2 (Lieferbedingungen am Lieferanten, E3)',
    ];

    /** Präfixe für die Detail-Blöcke der Vorlage (Stufe S1c). */
    public const SPAETER_PRAEFIXE = [
        'naehrwert' => 'S1c (item_nutritionals)',
        'allergen' => 'S1c (item_allergens)',
        'zusatzstoff' => 'S1c (item_declarations)',
        'deklaration' => 'S1c (item_declarations)',
    ];

    /**
     * Kanal-B-Import. Ohne `$apply` wird nichts geschrieben (Default beim Command),
     * der Bericht ist in beiden Modi identisch aufgebaut.
     *
     * @return array{
     *   datei: string, lieferant: string, zeilen: int, apply: bool,
     *   neu: int, aktualisiert: int, unveraendert: int, uebersprungen: int, fehler: int,
     *   spalten: array{erkannt: list<string>, spaeter: list<string>, unbekannt: list<string>},
     *   hinweise: list<string>, befunde: list<array<string, mixed>>
     * }
     */
    public function importiere(Team $team, int $supplierId, string $pfad, bool $apply = false): array
    {
        $supplier = FoodAlchemistSupplier::visibleToTeam($team)->whereKey($supplierId)->first();
        if (! $supplier) {
            throw new \RuntimeException('Lieferant nicht in der Team-Kette sichtbar (--supplier prüfen).');
        }

        $datei = $this->liesDatei($pfad);

        $bericht = [
            'datei' => basename($pfad),
            'lieferant' => (string) $supplier->name,
            'zeilen' => count($datei['zeilen']),
            'apply' => $apply,
            'neu' => 0, 'aktualisiert' => 0, 'unveraendert' => 0, 'uebersprungen' => 0, 'fehler' => 0,
            'spalten' => $datei['spalten'],
            'hinweise' => $datei['hinweise'],
            'befunde' => [],
        ];

        foreach ($datei['zeilen'] as $zeile) {
            $bericht['befunde'][] = $befund = $this->verarbeiteZeile($team, $supplierId, $zeile, $apply);
            $bericht[$befund['status']]++;
        }

        return $bericht;
    }

    /**
     * Datei-Reader. CSV/TSV, UTF-8 (BOM wird geschluckt), Trennzeichen wird an der
     * Kopfzeile erkannt (`;` `,` Tab `|`).
     *
     * **Bewusste Abweichung von E1 („xlsx/csv"):** ein xlsx-Reader hieße eine neue
     * Composer-Abhängigkeit im Modul (PhpSpreadsheet/openspout) — das ist eine
     * Entscheidung für Dominique, nicht für einen Import-Chunk. Bis dahin nennt der
     * Reader den Weg beim Namen („als CSV exportieren") statt zu scheitern.
     *
     * @return array{zeilen: list<array{nr: int, werte: array<string, string>}>, spalten: array{erkannt: list<string>, spaeter: list<string>, unbekannt: list<string>}, hinweise: list<string>}
     */
    public function liesDatei(string $pfad): array
    {
        if (! is_file($pfad) || ! is_readable($pfad)) {
            throw new \RuntimeException("Datei nicht lesbar: {$pfad}");
        }
        $endung = strtolower((string) pathinfo($pfad, PATHINFO_EXTENSION));
        if (in_array($endung, ['xlsx', 'xls', 'xlsm', 'ods'], true)) {
            throw new \RuntimeException(
                "Tabellen-Format [{$endung}] kann diese Stufe nicht lesen (keine Spreadsheet-Abhängigkeit im Modul). "
                . 'Bitte in der Tabellen-App als CSV (Trennzeichen ;) exportieren und die CSV übergeben.'
            );
        }
        if (! in_array($endung, ['csv', 'tsv', 'txt'], true)) {
            throw new \RuntimeException("Unbekannte Endung [{$endung}] — erwartet .csv oder .tsv.");
        }

        $roh = file_get_contents($pfad);
        if ($roh === false || trim($roh) === '') {
            throw new \RuntimeException('Datei ist leer.');
        }
        $roh = preg_replace('/^\xEF\xBB\xBF/', '', $roh) ?? $roh; // BOM
        $zeilenRoh = preg_split("/\r\n|\n|\r/", $roh) ?: [];

        $kopf = null;
        $kopfIndex = 0;
        foreach ($zeilenRoh as $i => $z) {
            if (trim($z) !== '') {
                $kopf = $z;
                $kopfIndex = $i;
                break;
            }
        }
        if ($kopf === null) {
            throw new \RuntimeException('Keine Kopfzeile gefunden.');
        }

        $trenner = $this->trenner($kopf);
        $kopfFelder = str_getcsv($kopf, $trenner);

        // Header → kanonischer Feldname. Doppelte Zuordnungen sind ein Fehler:
        // „Artikel-Nr" und „ArtNr" in derselben Datei ist keine Vorlage, das ist ein Rätsel.
        $zuordnung = [];   // Spalten-Index => kanonischer Name
        $erkannt = [];
        $spaeter = [];
        $unbekannt = [];
        $hinweise = [];
        foreach ($kopfFelder as $idx => $titel) {
            $norm = $this->normHeader((string) $titel);
            if ($norm === '') {
                continue;
            }
            $kanon = $this->kanonisch($norm);
            if ($kanon !== null) {
                if (in_array($kanon, $zuordnung, true)) {
                    throw new \RuntimeException("Spalte [{$titel}] doppelt belegt (bereits erkannt als: {$kanon}) — Vorlage bereinigen.");
                }
                $zuordnung[$idx] = $kanon;
                $erkannt[] = $kanon;

                continue;
            }
            if ($stufe = $this->spaetereStufe($norm)) {
                $spaeter[] = trim((string) $titel) . ' → ' . $stufe;

                continue;
            }
            $unbekannt[] = trim((string) $titel);
        }

        if (! in_array('bezeichnung', $erkannt, true)) {
            throw new \RuntimeException('Pflicht-Spalte „Bezeichnung" fehlt — nichts importiert.');
        }
        if (! in_array('artikelnummer', $erkannt, true) && ! in_array('eangebinde', $erkannt, true) && ! in_array('eanbestelleinheit', $erkannt, true)) {
            throw new \RuntimeException('Weder „Artikel-Nr" noch eine EAN-Spalte vorhanden — ohne Schlüssel ist kein Upsert möglich (E2).');
        }
        if ($spaeter !== []) {
            $hinweise[] = 'Erkannt, aber von DIESER Stufe NICHT geschrieben: ' . implode(' · ', $spaeter);
        }
        if ($unbekannt !== []) {
            $hinweise[] = 'Nicht Teil der Vorlage, ignoriert: ' . implode(' · ', $unbekannt);
        }

        $zeilen = [];
        foreach ($zeilenRoh as $i => $z) {
            if ($i <= $kopfIndex || trim($z) === '') {
                continue;
            }
            if (count($zeilen) >= self::MAX_ZEILEN) {
                $hinweise[] = 'Abgeschnitten bei ' . self::MAX_ZEILEN . ' Zeilen (MAX_ZEILEN) — Datei splitten.';
                break;
            }
            $felder = str_getcsv($z, $trenner);
            $werte = [];
            foreach ($zuordnung as $idx => $kanon) {
                $werte[$kanon] = trim((string) ($felder[$idx] ?? ''));
            }
            $zeilen[] = ['nr' => $i + 1, 'werte' => $werte];
        }

        return [
            'zeilen' => $zeilen,
            'spalten' => ['erkannt' => $erkannt, 'spaeter' => $spaeter, 'unbekannt' => $unbekannt],
            'hinweise' => $hinweise,
        ];
    }

    // ---- intern -----------------------------------------------------------

    /**
     * Eine Datei-Zeile → Bestand. Gibt IMMER einen Befund zurück (auch im Fehlerfall),
     * damit der Bericht so viele Zeilen hat wie die Datei.
     *
     * @param  array{nr: int, werte: array<string, string>}  $zeile
     * @return array<string, mixed>
     */
    private function verarbeiteZeile(Team $team, int $supplierId, array $zeile, bool $apply): array
    {
        $w = $zeile['werte'];
        $befund = ['zeile' => $zeile['nr'], 'artikel' => $w['artikelnummer'] ?? '', 'bezeichnung' => $w['bezeichnung'] ?? ''];

        $artno = ($w['artikelnummer'] ?? '') !== '' ? $w['artikelnummer'] : null;
        $eans = array_values(array_filter([$w['eangebinde'] ?? '', $w['eanbestelleinheit'] ?? '']));
        if ($artno === null && $eans === []) {
            return $befund + ['status' => 'fehler', 'grund' => 'Zeile ohne Artikelnummer und ohne EAN — kein Upsert-Schlüssel (E2).'];
        }

        $matchArt = null;
        try {
            $treffer = $this->finde($team, $supplierId, $artno, $eans, $matchArt);
        } catch (\RuntimeException $e) {
            return $befund + ['status' => 'fehler', 'grund' => $e->getMessage()];
        }
        $befund['match'] = $matchArt ?? 'neu';

        $werte = $this->mappeWerte($w, $warnungen);

        if ($treffer === null) {
            if (($werte['designation'] ?? '') === '') {
                return $befund + ['status' => 'fehler', 'grund' => 'Neuer Artikel ohne Bezeichnung.'];
            }
            if ($apply) {
                FoodAlchemistSupplierItem::create($werte + [
                    'team_id' => $team->id,
                    'supplier_id' => $supplierId,
                ]);
            }

            return $befund + ['status' => 'neu', 'felder' => array_keys($werte), 'warnungen' => $warnungen];
        }

        // D1: geerbter Artikel des Eltern-Teams — nur der Besitzer pflegt ihn.
        if (! $treffer->isOwnedBy($team)) {
            return $befund + [
                'status' => 'uebersprungen',
                'grund' => "Artikel #{$treffer->id} gehört Team {$treffer->team_id} (geerbt) — Pflege nur durch das Besitzer-Team (D1).",
            ];
        }

        $diff = $this->diff($treffer, $werte);
        if ($diff === []) {
            return $befund + ['status' => 'unveraendert', 'warnungen' => $warnungen];
        }
        if ($apply) {
            $treffer->update($diff);
        }

        return $befund + ['status' => 'aktualisiert', 'felder' => array_keys($diff), 'warnungen' => $warnungen];
    }

    /**
     * Upsert-Schlüssel (E2): `(supplier_id, article_number)` zuerst, EAN als Fallback.
     * Der Fallback greift auch, wenn eine Artikelnummer dasteht, aber nichts trifft —
     * dann hat der Lieferant vermutlich neu nummeriert, und die EAN ist die stabilere
     * Identität. Mehr als ein Treffer ist ein Fehler, keine Wahl.
     */
    private function finde(Team $team, int $supplierId, ?string $artno, array $eans, ?string &$art): ?FoodAlchemistSupplierItem
    {
        $basis = fn () => FoodAlchemistSupplierItem::visibleToTeam($team)->where('supplier_id', $supplierId);

        if ($artno !== null) {
            $treffer = $basis()->where('article_number', $artno)->limit(2)->get();
            if ($treffer->count() > 1) {
                throw new \RuntimeException("Artikelnummer [{$artno}] trifft mehrere Bestandsartikel — Bestand bereinigen (Unique aus E2 fehlt noch).");
            }
            if ($treffer->count() === 1) {
                $art = 'artikelnummer';

                return $treffer->first();
            }
        }

        foreach ($eans as $ean) {
            $treffer = $basis()->where(fn ($q) => $q->where('ean_packaging', $ean)->orWhere('ean_ordering', $ean))->limit(2)->get();
            if ($treffer->count() > 1) {
                throw new \RuntimeException("EAN [{$ean}] trifft mehrere Bestandsartikel — Bestand bereinigen.");
            }
            if ($treffer->count() === 1) {
                $art = 'ean';

                return $treffer->first();
            }
        }

        return null;
    }

    /**
     * Datei-Werte → Spalten-Werte. Leere Zellen fallen heraus (Regel 1: eine leere
     * Zelle löscht nichts). Unplausibles wird zur Warnung, nicht zum Fehler — die
     * Zeile bleibt verwertbar, das Feld bleibt ungesetzt.
     *
     * @param  array<string, string>  $w
     * @param  list<string>|null  $warnungen
     * @return array<string, mixed>
     */
    private function mappeWerte(array $w, ?array &$warnungen): array
    {
        $warnungen = [];
        $out = [];
        foreach (self::FELDER as $kanon => [$spalte, $typ, $_]) {
            if (! array_key_exists($kanon, $w) || $w[$kanon] === '') {
                continue;
            }
            $roh = $w[$kanon];
            switch ($typ) {
                case 'decimal':
                    $z = $this->zahl($roh);
                    if ($z === null) {
                        $warnungen[] = "{$kanon}: [{$roh}] ist keine Zahl — Feld nicht gesetzt.";

                        continue 2;
                    }
                    $out[$spalte] = $z;
                    break;
                case 'int':
                    $z = $this->zahl($roh);
                    if ($z === null || $z < 0) {
                        $warnungen[] = "{$kanon}: [{$roh}] ist keine gültige Anzahl — Feld nicht gesetzt.";

                        continue 2;
                    }
                    $out[$spalte] = (int) round($z);
                    break;
                case 'bool':
                    $b = $this->wahrheit($roh);
                    if ($b === null) {
                        $warnungen[] = "{$kanon}: [{$roh}] ist kein ja/nein — Feld nicht gesetzt.";

                        continue 2;
                    }
                    $out[$spalte] = $b;
                    break;
                case 'unit':
                    $u = $this->einheit($roh);
                    if ($u === null) {
                        $warnungen[] = "einheit: [{$roh}] ist keine Kalkulationseinheit (" . implode('|', self::UNIT_CODES) . ') — Feld nicht gesetzt.';

                        continue 2;
                    }
                    $out[$spalte] = $u;
                    break;
                default:
                    $out[$spalte] = $roh;
            }
        }

        return $out;
    }

    /**
     * Was sich wirklich ändert. Zahlen werden numerisch, Wahrheitswerte als bool
     * und Texte getrimmt verglichen — sonst meldete jeder Lauf „aktualisiert",
     * weil „2.000" ≠ „2" ist, und die Idempotenz-Zusage wäre wertlos.
     *
     * @param  array<string, mixed>  $werte
     * @return array<string, mixed>
     */
    private function diff(FoodAlchemistSupplierItem $item, array $werte): array
    {
        $diff = [];
        foreach ($werte as $spalte => $neu) {
            $alt = $item->getAttribute($spalte);
            if (is_bool($neu)) {
                if ($alt !== null && (bool) $alt === $neu) {
                    continue;
                }
            } elseif (is_int($neu) || is_float($neu)) {
                if ($alt !== null && abs((float) $alt - (float) $neu) < 0.0005) {
                    continue;
                }
            } else {
                if (trim((string) $alt) === trim((string) $neu)) {
                    continue;
                }
            }
            $diff[$spalte] = $neu;
        }

        return $diff;
    }

    /** Trennzeichen der Kopfzeile — das häufigste der Kandidaten gewinnt. */
    private function trenner(string $kopf): string
    {
        $beste = ';';
        $max = -1;
        foreach ([';', "\t", ',', '|'] as $kandidat) {
            $n = substr_count($kopf, $kandidat);
            if ($n > $max) {
                $max = $n;
                $beste = $kandidat;
            }
        }

        return $beste;
    }

    /** Header-Normalisierung: Umlaute, Groß/Klein, Satz- und Leerzeichen sind egal. */
    private function normHeader(string $titel): string
    {
        $t = mb_strtolower(trim($titel));
        $t = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $t);

        return (string) preg_replace('/[^a-z0-9]/', '', $t);
    }

    private function kanonisch(string $norm): ?string
    {
        foreach (self::FELDER as $kanon => [$_s, $_t, $aliase]) {
            if ($norm === $kanon || in_array($norm, $aliase, true)) {
                return $kanon;
            }
        }

        return null;
    }

    private function spaetereStufe(string $norm): ?string
    {
        if (isset(self::SPAETER[$norm])) {
            return self::SPAETER[$norm];
        }
        foreach (self::SPAETER_PRAEFIXE as $praefix => $stufe) {
            if (str_starts_with($norm, $praefix)) {
                return $stufe;
            }
        }

        return null;
    }

    /** Deutsche und englische Zahlschreibweise, inkl. Tausenderpunkt und €/%-Suffix. */
    private function zahl(string $roh): ?float
    {
        $s = trim(str_replace(['€', '%', ' ', "\u{00A0}"], '', $roh));
        if ($s === '') {
            return null;
        }
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $s = str_replace('.', '', $s);          // Punkt = Tausendertrenner
        }
        $s = str_replace(',', '.', $s);
        if (! is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }

    private function wahrheit(string $roh): ?bool
    {
        $s = mb_strtolower(trim($roh));
        if (in_array($s, ['ja', 'j', 'yes', 'y', '1', 'true', 'wahr', 'x'], true)) {
            return true;
        }
        if (in_array($s, ['nein', 'n', 'no', '0', 'false', 'falsch', '-'], true)) {
            return false;
        }

        return null;
    }

    /** kg|l|Stk — die drei Kalkulationseinheiten, tolerant geschrieben. */
    private function einheit(string $roh): ?string
    {
        $s = mb_strtolower(trim(str_replace('.', '', $roh)));
        $map = [
            'kg' => 'kg', 'kilo' => 'kg', 'kilogramm' => 'kg',
            'l' => 'l', 'ltr' => 'l', 'liter' => 'l',
            'stk' => 'Stk', 'st' => 'Stk', 'stueck' => 'Stk', 'stück' => 'Stk', 'stk.' => 'Stk', 'piece' => 'Stk', 'pce' => 'Stk',
        ];

        return $map[$s] ?? null;
    }

    /**
     * Lauf-Bookkeeping in `foodalchemist_bulk_runs` (Typ `ingest`) — dieselbe Tabelle
     * wie Anreicherungs- und Review-Läufe, damit „welche Läufe sind gelaufen?" eine
     * Antwort hat und nicht drei. Grundlage für S3 `ingest.STATUS`.
     */
    public function starteRun(int $teamId, int $total): int
    {
        DB::table('foodalchemist_bulk_runs')->insert([
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
            'team_id' => $teamId, 'type' => 'ingest', 'status' => 'running',
            'total' => $total, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    }

    /** @param array<string, mixed> $bericht */
    public function beendeRun(int $runId, array $bericht): void
    {
        DB::table('foodalchemist_bulk_runs')->where('id', $runId)->update([
            'status' => 'done',
            'done' => (int) $bericht['neu'] + (int) $bericht['aktualisiert'] + (int) $bericht['unveraendert'] + (int) $bericht['uebersprungen'],
            'failed' => (int) $bericht['fehler'],
            'updated_at' => now(),
        ]);
    }
}
