<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;
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
 * Was diese Stufe schreibt: den Artikel-Stamm (`foodalchemist_supplier_items`),
 * seit S1b den **Preis** (`foodalchemist_prices` über {@see PriceService::createFor},
 * append-only), seit S1c die drei **Detail-Blöcke** (`item_nutritionals` /
 * `item_allergens` / `item_declarations` über den SupplierItemService) und seit S2 die
 * **Lieferbedingungen** am Lieferanten (E3, über {@see SupplierService::updateConditions})
 * — alles samt **Post-Import-Kette (E4)**: bewegter Artikel → betroffene GPs →
 * `RecipeRecomputeService::recomputeMany` über die nutzenden Rezepte (V-049: eine Menge,
 * ein Lauf) → `SignalDetektorService::preisSprungMargeImpact` mit **genau dieser** GP-Menge
 * (V-050) statt der Team-weiten Suche.
 * Das ist der DoD-Kern der Spec: keine stille Drift — weder ein neuer EK noch ein
 * neues Allergen darf am Artikel stehen, während Rezeptkosten, Aushang und
 * Marge-Signale den alten Stand zeigen.
 *
 * Sechs tragende Regeln:
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
 *  4. **Ein unveränderter Preis erzeugt keine Preis-Zeile (S1b).** `createFor` ist
 *     append-only; würde jeder Lauf schreiben, wäre die Historie nach drei Quartalen
 *     Rauschen und `preisTrendBulk` läse „Δ 0 %" als jüngste Generation. Geschrieben
 *     wird nur, wenn sich der aktive Preis wirklich bewegt.
 *  5. **Die Kette läuft EINMAL am Ende, nicht je Zeile (S1b).** Ein Katalog rührt
 *     dieselben Eltern-Rezepte hundertfach an; je Zeile zu propagieren hieße, dasselbe
 *     Gericht hundertmal zu rechnen. Gesammelt wird über den ganzen Lauf, gerechnet
 *     über die deduplizierte Menge.
 *  6. **Lieferbedingungen gelten dem Lieferanten, nicht der Zeile (S2).** Sie stehen in
 *     einer Artikel-Datei zwangsläufig n-mal da (oder nur in der ersten Zeile). Gelesen
 *     wird die ganze Datei, geschrieben **einmal** — und widersprechen sich zwei Zeilen
 *     in derselben Kondition, wird sie abgelehnt statt geraten (Regel 3 auf Datei-Ebene).
 */
class FileArticleImportService
{
    /** Zeilen-Obergrenze je Lauf — schützt vor versehentlich geladenen Voll-Katalogen. */
    public const MAX_ZEILEN = 20000;

    /*
     * Die Obergrenze `MAX_RECOMPUTE = 1000` ist mit V-049 (12·S2a-1b) **entfallen**.
     * Sie war eine Krücke gegen ein Problem im Recompute-Service: je Direktnutzer eine
     * eigene Eltern-BFS samt Transaktion, gemeinsame Gerichte hundertfach gerechnet.
     * `RecipeRecomputeService::recomputeMany()` löst die betroffene Menge einmal auf und
     * rechnet jedes Rezept genau einmal — das ist die minimal nötige Arbeit, und ein
     * Deckel darauf wäre kein Schutz mehr, sondern genau die stille Drift, gegen die
     * E4 geschrieben ist (der Rest bliebe unbemerkt stale). Die verbleibende Schranke
     * ist der Job-Timeout (`ImportArticlesJob`, 900 s); der Bericht nennt die Zahl der
     * neu gerechneten Rezepte.
     */

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

    /**
     * Preis-Spalten (S1b). Bewusst NICHT in {@see FELDER}: ihr Ziel ist keine Spalte
     * am Artikel, sondern eine neue Zeile in `foodalchemist_prices` — der Schreibweg
     * ist {@see PriceService::createFor} und nicht `$item->update()`.
     *
     * @var array<string, list<string>>
     */
    public const PREIS_FELDER = [
        'preis' => ['preis', 'ekpreis', 'ek', 'einkaufspreis', 'nettopreis', 'price'],
        'preisstatus' => ['preisstatus', 'preisart', 'pricestatus'],
    ];

    /** Erlaubte Kalkulationseinheiten — dieselbe Liste wie bei der Hand-Anlage (GL-11). */
    public const UNIT_CODES = SupplierItemService::UNIT_CODES;

    /**
     * Spalten der Vorlage, die im Ziel-Schema gar kein Feld haben. Sie werden benannt
     * statt unter „nicht Teil der Vorlage" zu verschwinden — sonst hielte der Bediener
     * eine bewusste Auslassung für einen Tippfehler in seiner Kopfzeile.
     *
     * @var array<string, string>
     */
    public const OHNE_ZIEL = [
        'preisnotiz' => 'Preis-Zeilen tragen keine Notiz (kein Ziel-Feld) — ignoriert',
    ];

    /**
     * Spalten, die zur Vorlage gehören, aber erst eine spätere Stufe schreibt. Sie
     * werden erkannt und im Bericht namentlich genannt (nie still ignoriert).
     *
     * **Derzeit leer** — S1b (Preis), S1c (Detail-Blöcke) und S2 (Lieferbedingungen)
     * haben die letzten Einträge abgeräumt; die Vorlage wird jetzt vollständig
     * geschrieben. Die Mechanik bleibt bewusst stehen: sie ist der Ort, an dem eine
     * künftige Spalte ehrlich „erkannt, aber noch nicht geschrieben" sagen kann,
     * statt unter „nicht Teil der Vorlage" zu verschwinden.
     *
     * @var array<string, string>
     */
    public const SPAETER = [];

    /**
     * S2 (E3) — die **Lieferbedingungen**. Ihr Ziel ist keine Spalte am Artikel, sondern
     * `foodalchemist_suppliers`: sie gelten dem Lieferanten, nicht der Zeile (Regel 6).
     * Der Schreibweg ist {@see SupplierService::updateConditions} — dort hängen die
     * D1-Prüfung und die Spalten-Whitelist schon dran, ein `$supplier->update()` hier
     * wäre der zweite Pfad.
     *
     * Die vier Spalten sind genau die aus E3/E4 (geteilte Migration mit R9). Die drei
     * Bestell-Logistik-Felder derselben Tabelle (`delivery_days` / `order_cutoff_time`
     * / `order_lead_days`, Spec 17) sind **bewusst nicht** dabei: sie beschreiben die
     * Bestellschiene, nicht die kommerzielle Kondition, stehen in keiner Preisliste und
     * werden im Lieferanten-Modal gepflegt.
     *
     * @var array<string, array{0: string, 1: string, 2: list<string>}>  Ziel-Spalte => [Label, Typ, Aliase]
     */
    public const KONDITIONS_FELDER = [
        'min_order_value' => ['Mindestbestellwert', 'geld', ['mindestbestellwert', 'mindestauftragswert', 'mindestbestellwertnetto', 'mbw', 'minbestellwert']],
        'free_shipping_threshold' => ['Frei-Haus-Grenze', 'geld', ['freihausab', 'freihaus', 'freihausgrenze', 'frachtfreiab', 'versandkostenfreiab']],
        'payment_term_days' => ['Zahlungsziel', 'tage', ['zahlungsziel', 'zahlungszieltage', 'zahlungsfrist', 'nettotage']],
        'rebate_pct' => ['Rückvergütung', 'prozent', ['rueckverguetung', 'rueckverguetungprozent', 'bonus', 'bonusprozent', 'jahresbonus']],
    ];

    /** Obergrenze eines plausiblen Zahlungsziels in Tagen — darüber ist es ein Tippfehler, keine Kondition. */
    public const MAX_ZAHLUNGSZIEL_TAGE = 365;

    /**
     * S1c — die drei Detail-Blöcke. Ihre Spalten heißen `<Präfix> <Wert>`
     * (`Nährwert kcal`, `Allergen Milch`, `Zusatzstoff Farbstoff`); der Präfix wählt
     * den Block, der Rest den Schlüssel. Kanonisch wird daraus `nw:…` / `al:…` / `dk:…`
     * — bewusst ein eigener Namensraum, damit eine Detail-Spalte nie mit einem
     * Artikel-Feld kollidiert (`Allergen Fisch` vs. eine künftige Spalte `Fisch`).
     *
     * @var array<string, array{0: string, 1: string}>  Präfix => [Kürzel, Klartext]
     */
    public const DETAIL_PRAEFIXE = [
        'naehrwerte' => ['nw', 'Nährwert'],
        'naehrwert' => ['nw', 'Nährwert'],
        'allergene' => ['al', 'Allergen'],
        'allergen' => ['al', 'Allergen'],
        'zusatzstoffe' => ['dk', 'Zusatzstoff'],
        'zusatzstoff' => ['dk', 'Zusatzstoff'],
        'deklarationen' => ['dk', 'Zusatzstoff'],
        'deklaration' => ['dk', 'Zusatzstoff'],
    ];

    /**
     * Nährwert-Ziele: nur die **8 Kernwerte** aus {@see SupplierItemService::NAEHRWERT_FELDER}.
     * Das ist kein Zufalls-Ausschnitt, sondern derselbe Umfang, den `setNutrition` schreibt
     * — die 36 übrigen BLS-Spalten hat bisher niemand gepflegt, und ein zweiter Schreibweg
     * nur für sie wäre der zweite Pfad, den die Spec ausdrücklich nicht will.
     *
     * @var array<string, list<string>>
     */
    public const NAEHRWERT_SPALTEN = [
        'energy_kcal' => ['kcal', 'energie', 'energiekcal', 'brennwert', 'brennwertkcal'],
        'energy_kj' => ['kj', 'energiekj', 'brennwertkj'],
        'protein' => ['eiweiss', 'protein'],
        'fat' => ['fett', 'fat'],
        'saturated_fat' => ['gesaettigtefettsaeuren', 'davongesaettigtefettsaeuren', 'gesaettigtefette', 'saturatedfat'],
        'carbs_absorbable' => ['kohlenhydrate', 'kh', 'carbs'],
        'sugar' => ['zucker', 'davonzucker', 'sugar'],
        'sodium' => ['natrium', 'sodium'],
    ];

    /**
     * LMIV-Label „Salz" — der Wert, den echte Lieferanten-Datenblätter tragen. Die
     * Tabelle kennt nur Natrium (mg), darum wird nach GL-08 **umgerechnet**:
     * Salz (g) = Natrium (mg) × 0,0025 ⇒ Natrium = Salz × 400. Steht beides in der
     * Datei, gewinnt Natrium (der ungerechnete Wert) und Salz wird als Warnung gemeldet.
     */
    public const NAEHRWERT_SALZ = ['salz', 'salt'];
    public const SALZ_ZU_NATRIUM = 400.0;

    /** Allergen-Ziele (14 EU) — deutsche Datenblatt-Wörter je Schlüssel. */
    public const ALLERGEN_SPALTEN = [
        'gluten' => ['gluten', 'glutenhaltigesgetreide', 'getreide'],
        'crustaceans' => ['krebstiere', 'krustentiere', 'crustaceans'],
        'eggs' => ['ei', 'eier', 'eggs'],
        'fish' => ['fisch', 'fish'],
        'peanuts' => ['erdnuss', 'erdnuesse', 'peanuts'],
        'soy' => ['soja', 'sojabohnen', 'soy'],
        'milk' => ['milch', 'milk'],
        'tree_nuts' => ['schalenfruechte', 'nuesse', 'hartschalenobst', 'treenuts'],
        'celery' => ['sellerie', 'celery'],
        'mustard' => ['senf', 'mustard'],
        'sesame' => ['sesam', 'sesamsamen', 'sesame'],
        'sulphites' => ['sulfite', 'schwefeldioxid', 'schwefeldioxidundsulfite', 'sulphites'],
        'lupin' => ['lupine', 'lupinen', 'lupin'],
        'molluscs' => ['weichtiere', 'mollusken', 'molluscs'],
    ];

    /** Deklarations-Ziele (18 LMIV) — Kurzwort je Schlüssel, Labels in {@see FoodAlchemistItemDeclaration::STOFFE}. */
    public const DEKLARATION_SPALTEN = [
        'with_dye' => ['farbstoff', 'mitfarbstoff'],
        'with_preservative' => ['konservierungsstoff', 'mitkonservierungsstoff', 'konserviert'],
        'with_antioxidant' => ['antioxidationsmittel', 'mitantioxidationsmittel'],
        'with_flavour_enhancer' => ['geschmacksverstaerker', 'mitgeschmacksverstaerker'],
        'sulphurated' => ['geschwefelt'],
        'blackened' => ['geschwaerzt'],
        'waxed' => ['gewachst'],
        'with_phosphate' => ['phosphat', 'mitphosphat'],
        'with_sweetener' => ['suessungsmittel', 'mitsuessungsmittel'],
        'contains_phenylalanine' => ['phenylalanin', 'phenylalaninquelle'],
        'excessive_consumption_laxative' => ['abfuehrend', 'abfuehrendewirkung'],
        'packaged_modified_atmosphere' => ['schutzatmosphaere', 'unterschutzatmosphaere'],
        'caffeinated' => ['koffeinhaltig', 'koffein'],
        'contains_milk_protein' => ['milcheiweiss'],
        'contains_quinine' => ['chinin', 'chininhaltig'],
        'taurine_containing' => ['taurin', 'taurinhaltig'],
        'can_impair_attention_children' => ['aufmerksamkeitkinder', 'aktivitaetkinder'],
        'with_type_sugar_sweetener' => ['zuckerartenundsuessungsmittel', 'zuckerartensuessungsmittel'],
    ];

    public function __construct(
        private PriceService $preise,
        private RecipeRecomputeService $recompute,
        private SupplierItemService $artikel,
        private SupplierService $lieferanten,
    ) {
    }

    /**
     * Kanal-B-Import. Ohne `$apply` wird nichts geschrieben (Default beim Command),
     * der Bericht ist in beiden Modi identisch aufgebaut — auch die Kette meldet im
     * Trockenlauf, WAS sie neu rechnen würde (die Ermittlung ist reines Lesen).
     *
     * @return array{
     *   datei: string, lieferant: string, zeilen: int, apply: bool,
     *   neu: int, aktualisiert: int, unveraendert: int, uebersprungen: int, fehler: int,
     *   preise: array{neu: int, geaendert: int, unveraendert: int, fehler: int},
     *   details: array{naehrwerte: int, allergene: int, zusatzstoffe: int},
     *   konditionen: array{status: ?string, grund?: string, gesetzt: array<string, mixed>, unveraendert: list<string>, abgelehnt: list<string>},
     *   kette: array{bewegt: int, gps: int, rezepte: int, neu_berechnet: int, signale: int},
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
            'preise' => ['neu' => 0, 'geaendert' => 0, 'unveraendert' => 0, 'fehler' => 0],
            'details' => ['naehrwerte' => 0, 'allergene' => 0, 'zusatzstoffe' => 0],
            'konditionen' => ['status' => null, 'gesetzt' => [], 'unveraendert' => [], 'abgelehnt' => []],
            'kette' => ['bewegt' => 0, 'gps' => 0, 'rezepte' => 0, 'neu_berechnet' => 0, 'signale' => 0],
            'spalten' => $datei['spalten'],
            'hinweise' => $datei['hinweise'],
            'befunde' => [],
        ];

        $bewegteItems = [];   // Artikel mit tatsächlich geändertem Preis → Kette (E4)

        foreach ($datei['zeilen'] as $zeile) {
            $bericht['befunde'][] = $befund = $this->verarbeiteZeile($team, $supplierId, $zeile, $apply, $bewegteItems);
            $bericht[$befund['status']]++;
            if (isset($befund['preis']['status'])) {
                $bericht['preise'][$befund['preis']['status']]++;
            }
            foreach (array_keys($bericht['details']) as $block) {
                if (($befund['details'][$block] ?? []) !== []) {
                    $bericht['details'][$block]++;
                }
            }
        }

        $bericht['konditionen'] = $this->verarbeiteKonditionen($team, $supplier, $datei['zeilen'], $apply);
        $bericht['kette'] = $this->postImportKette($team, $bewegteItems, $apply);

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
        $ohneZiel = [];
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
            $detail = $this->detailSpalte($norm, $detailHinweis);
            if ($detail !== null) {
                if (in_array($detail, $zuordnung, true)) {
                    throw new \RuntimeException("Spalte [{$titel}] doppelt belegt (bereits erkannt als: {$detail}) — Vorlage bereinigen.");
                }
                $zuordnung[$idx] = $detail;
                $erkannt[] = $detail;

                continue;
            }
            if ($detailHinweis !== null) {
                $ohneZiel[] = trim((string) $titel) . ' → ' . $detailHinweis;

                continue;
            }
            if (isset(self::OHNE_ZIEL[$norm])) {
                $ohneZiel[] = trim((string) $titel) . ' → ' . self::OHNE_ZIEL[$norm];

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
        if (in_array('preisstatus', $erkannt, true) && ! in_array('preis', $erkannt, true)) {
            throw new \RuntimeException('Spalte „Preis-Status" ohne „Preis" — ein Status ohne Betrag ergibt keine Preis-Zeile.');
        }
        if ($spaeter !== []) {
            $hinweise[] = 'Erkannt, aber von DIESER Stufe NICHT geschrieben: ' . implode(' · ', $spaeter);
        }
        if ($ohneZiel !== []) {
            $hinweise[] = 'Ohne Ziel-Feld im Schema: ' . implode(' · ', $ohneZiel);
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
     * @param  array<int, true>  $bewegteItems  wird um Artikel mit geändertem Preis ergänzt (E4)
     * @return array<string, mixed>
     */
    private function verarbeiteZeile(Team $team, int $supplierId, array $zeile, bool $apply, array &$bewegteItems): array
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
            $neu = null;
            if ($apply) {
                $neu = FoodAlchemistSupplierItem::create($werte + [
                    'team_id' => $team->id,
                    'supplier_id' => $supplierId,
                ]);
            }

            $nachlauf = $this->verarbeitePreis($team, $neu, $w, $apply, $bewegteItems)
                + $this->verarbeiteDetails($team, $neu, $w, $apply, $bewegteItems, $warnungen);

            return $befund + ['status' => 'neu', 'felder' => array_keys($werte), 'warnungen' => $warnungen] + $nachlauf;
        }

        // D1: geerbter Artikel des Eltern-Teams — nur der Besitzer pflegt ihn.
        if (! $treffer->isOwnedBy($team)) {
            return $befund + [
                'status' => 'uebersprungen',
                'grund' => "Artikel #{$treffer->id} gehört Team {$treffer->team_id} (geerbt) — Pflege nur durch das Besitzer-Team (D1).",
            ];
        }

        $nachlauf = $this->verarbeitePreis($team, $treffer, $w, $apply, $bewegteItems)
            + $this->verarbeiteDetails($team, $treffer, $w, $apply, $bewegteItems, $warnungen);

        $diff = $this->diff($treffer, $werte);
        if ($diff === []) {
            return $befund + ['status' => 'unveraendert', 'warnungen' => $warnungen] + $nachlauf;
        }
        if ($apply) {
            $treffer->update($diff);
        }

        return $befund + ['status' => 'aktualisiert', 'felder' => array_keys($diff), 'warnungen' => $warnungen] + $nachlauf;
    }

    /**
     * S1b — die Preis-Hälfte einer Zeile. Gibt `[]` zurück, wenn die Datei gar keine
     * Preis-Spalte hat (dann taucht im Befund kein Preis-Block auf und der Bericht
     * behauptet nichts über etwas, das nicht in der Datei stand).
     *
     * Drei Regeln, die hier zusammenkommen:
     *  - **Nur echte Bewegung schreibt.** Gleicher Preis ⇒ keine neue Zeile (Regel 4).
     *  - **0,00 € ist ein Fehler, kein Preis.** Mit Status `0` wäre eine Null-Zeile
     *    nach GL-11 ein *aktiver* Standard-EK und würde die Rezeptkosten der ganzen
     *    Kette auf null ziehen — ein grün gemeldeter Falschpreis. Wer eine Datenlücke
     *    abbilden will, lässt die Zelle leer.
     *  - **Negatives ist ein Service-Zuschlag** (GL-11 I5) und wird nicht per Massen-
     *    Import angelegt; `createFor` lehnt es ohnehin ab, hier fällt es als Zeilen-
     *    Befund an statt als Ausnahme, die den Lauf reißt.
     *
     * @param  array<string, string>  $w
     * @param  array<int, true>  $bewegteItems
     * @return array{preis?: array<string, mixed>}
     */
    private function verarbeitePreis(Team $team, ?FoodAlchemistSupplierItem $item, array $w, bool $apply, array &$bewegteItems): array
    {
        if (! array_key_exists('preis', $w) || $w['preis'] === '') {
            return [];
        }

        $roh = $w['preis'];
        $preis = $this->zahl($roh);
        if ($preis === null) {
            return ['preis' => ['status' => 'fehler', 'grund' => "Preis [{$roh}] ist keine Zahl."]];
        }
        if ($preis < 0) {
            return ['preis' => ['status' => 'fehler', 'grund' => "Preis [{$roh}] ist negativ — Service-Zuschläge legt der Import nicht an (GL-11 I5)."]];
        }
        if ($preis === 0.0) {
            return ['preis' => ['status' => 'fehler', 'grund' => 'Preis 0,00 € wäre ein aktiver Null-EK und würde die Rezeptkosten auf null ziehen — Zelle leer lassen, wenn kein Preis bekannt ist.']];
        }

        $status = '0';
        if (($w['preisstatus'] ?? '') !== '') {
            $status = $this->preisStatus($w['preisstatus']) ?? '';
            if ($status === '') {
                return ['preis' => ['status' => 'fehler', 'grund' => "Preis-Status [{$w['preisstatus']}] ist weder Standard-EK noch Aktion."]];
            }
        }

        // Trockenlauf auf einem neuen Artikel: es gibt noch keine ID, gegen die man
        // vergleichen könnte. Der Befund sagt genau das, statt „unverändert" zu raten.
        if ($item === null) {
            return ['preis' => ['status' => 'neu', 'neu' => $preis, 'status_code' => $status]];
        }

        $aktiv = $this->preise->activeFor($item->id);
        $alt = $aktiv?->price !== null ? (float) $aktiv->price : null;
        if ($alt !== null && abs($alt - $preis) < 0.0005 && (string) $aktiv->status === $status) {
            return ['preis' => ['status' => 'unveraendert', 'alt' => $alt, 'neu' => $preis]];
        }

        if ($apply) {
            try {
                $this->preise->createFor($team, $item, $preis, $status);
            } catch (\RuntimeException $e) {
                return ['preis' => ['status' => 'fehler', 'grund' => $e->getMessage()]];
            }
            $bewegteItems[(int) $item->id] = true;
        } elseif ($item->exists) {
            $bewegteItems[(int) $item->id] = true;   // Trockenlauf: Kette darf trotzdem zeigen, was sie träfe
        }

        return ['preis' => [
            'status' => $alt === null ? 'neu' : 'geaendert',
            'alt' => $alt,
            'neu' => $preis,
            'status_code' => $status,
        ]];
    }

    /**
     * S1c — die drei Detail-Blöcke einer Zeile (Nährwerte, Allergene, Zusatzstoffe).
     *
     * Vier Regeln, die hier zusammenkommen:
     *
     *  - **Kein zweiter Schreibweg.** Geschrieben wird ausschließlich über
     *    {@see SupplierItemService::setNutrition/setAllergens/setDeclarations} — dort
     *    hängen D1-Prüfung, Wert-Vokabular und Lineage-Stempel schon dran.
     *  - **Die Setter ersetzen voll, der Import nicht.** Sie schreiben immer alle
     *    Felder ihres Blocks (die UI-Form postet auch alle). Ein Teil-Katalog mit einer
     *    Spalte `Allergen Milch` würde damit die übrigen 13 Werte auf `unbekannt`
     *    zurücksetzen — das Gegenteil von Regel 1. Darum wird hier **gemischt**:
     *    Ist-Stand lesen, die Datei-Werte darüberlegen, das Ganze zurückschreiben.
     *  - **Explizit „unbekannt" darf überschreiben, eine leere Zelle nicht.** Die leere
     *    Zelle heißt „steht nicht in der Datei" (Regel 1); ein hingeschriebenes
     *    „unbekannt" ist dagegen eine Aussage des Lieferanten und wird übernommen.
     *  - **Unlesbare Werte sind Warnungen, keine Zeilen-Fehler** — anders als beim Preis.
     *    Ein nicht gesetzter Allergen-Wert bleibt `unbekannt` und ist damit nach GL-01
     *    die konservative Seite; ein nicht gesetzter Preis wäre ein fehlender EK. Die
     *    Asymmetrie ist gewollt und steht so in der Vorlagen-Doku.
     *
     * Die geänderten Artikel wandern in `$bewegteItems` und damit in die **E4-Kette**:
     * Rezept-Allergene, -Zusatzstoffe und -Nährwerte sind aggregierte Felder
     * ({@see RecipeRecomputeService::recomputePipeline}), kein Live-Join. Ohne Recompute
     * stünde das neue Allergen am Artikel, während Rezept, Foodbook und Aushang den
     * alten Stand zeigten — dieselbe stille Drift wie beim Preis, nur mit Haftung.
     *
     * @param  array<string, string>  $w
     * @param  array<int, true>  $bewegteItems
     * @param  list<string>  $warnungen
     * @return array{details?: array{naehrwerte: list<string>, allergene: list<string>, zusatzstoffe: list<string>}}
     */
    private function verarbeiteDetails(Team $team, ?FoodAlchemistSupplierItem $item, array $w, bool $apply, array &$bewegteItems, array &$warnungen): array
    {
        $nw = $this->detailWerte($w, 'nw', $warnungen);
        $al = $this->detailWerte($w, 'al', $warnungen);
        $dk = $this->detailWerte($w, 'dk', $warnungen);
        if ($nw === [] && $al === [] && $dk === []) {
            return [];
        }

        // Trockenlauf auf einem neuen Artikel: es gibt noch keine Zeile, gegen die man
        // mischen könnte. Gemeldet wird, was ankäme — geraten wird nichts.
        if ($item === null || ! $item->exists) {
            return ['details' => [
                'naehrwerte' => array_keys($nw), 'allergene' => array_keys($al), 'zusatzstoffe' => array_keys($dk),
            ]];
        }

        $geaendert = ['naehrwerte' => [], 'allergene' => [], 'zusatzstoffe' => []];

        if ($nw !== []) {
            $ist = $this->artikel->getNutrition($item);
            $soll = $ist;
            foreach ($nw as $k => $v) {
                $soll[$k] = $v;
                $altZahl = ($ist[$k] ?? '') === '' ? null : (float) $ist[$k];
                if ($altZahl === null || abs($altZahl - (float) $v) >= 0.0005) {
                    $geaendert['naehrwerte'][] = $k;
                }
            }
            if ($geaendert['naehrwerte'] !== [] && $apply) {
                $this->artikel->setNutrition($team, $item, $soll);
            }
        }

        foreach ([['al', $al, 'allergene', 'getAllergens', 'setAllergens'], ['dk', $dk, 'zusatzstoffe', 'getDeclarations', 'setDeclarations']] as [$_k, $datei, $block, $lesen, $schreiben]) {
            if ($datei === []) {
                continue;
            }
            $ist = $this->artikel->{$lesen}($item);
            $soll = $ist;
            foreach ($datei as $k => $v) {
                $soll[$k] = $v;
                if (($ist[$k] ?? 'unbekannt') !== $v) {
                    $geaendert[$block][] = $k;
                }
            }
            if ($geaendert[$block] !== [] && $apply) {
                $this->artikel->{$schreiben}($team, $item, $soll, 'datei');
            }
        }

        if ($geaendert['naehrwerte'] !== [] || $geaendert['allergene'] !== [] || $geaendert['zusatzstoffe'] !== []) {
            $bewegteItems[(int) $item->id] = true;
        }

        return ['details' => $geaendert];
    }

    /**
     * Die befüllten Zellen eines Detail-Blocks → [Ziel-Schlüssel => Wert].
     * Leere Zellen fallen heraus (Regel 1), unlesbare werden zur Warnung.
     *
     * @param  array<string, string>  $w
     * @param  list<string>  $warnungen
     * @return array<string, mixed>
     */
    private function detailWerte(array $w, string $kuerzel, array &$warnungen): array
    {
        $out = [];
        $salz = null;
        foreach ($w as $kanon => $roh) {
            if (! str_starts_with($kanon, "{$kuerzel}:") || trim($roh) === '') {
                continue;
            }
            $key = substr($kanon, strlen($kuerzel) + 1);

            if ($kuerzel === 'nw') {
                $z = $this->zahl($roh);
                if ($z === null || $z < 0) {
                    $warnungen[] = "Nährwert {$key}: [{$roh}] ist keine gültige Zahl je 100 g — Wert nicht gesetzt.";

                    continue;
                }
                if ($key === '__salz') {
                    $salz = $z * self::SALZ_ZU_NATRIUM;
                } else {
                    $out[$key] = $z;
                }

                continue;
            }

            $wert = $kuerzel === 'al' ? $this->allergenWert($roh) : $this->deklarationsWert($roh);
            if ($wert === null) {
                $warnungen[] = ($kuerzel === 'al' ? 'Allergen' : 'Zusatzstoff')
                    . " {$key}: [{$roh}] ist kein zulässiger Wert — nicht gesetzt.";

                continue;
            }
            $out[$key] = $wert;
        }

        // Salz nur, wenn kein direkter Natriumwert dasteht: der ungerechnete gewinnt.
        if ($salz !== null) {
            if (array_key_exists('sodium', $out)) {
                $warnungen[] = 'Nährwert: Salz UND Natrium in derselben Zeile — Natrium gewinnt, Salz ignoriert.';
            } else {
                $out['sodium'] = $salz;
            }
        }

        return $out;
    }

    /**
     * S2 (E3) — die Lieferbedingungen der Datei → `foodalchemist_suppliers`.
     *
     * Vier Regeln, die hier zusammenkommen:
     *
     *  - **Eine Kondition gilt dem Lieferanten, nicht der Zeile (Regel 6).** Gelesen wird
     *    über die ganze Datei, geschrieben genau einmal am Ende. Dass derselbe
     *    Mindestbestellwert in 400 Artikelzeilen steht, ist der Normalfall, kein Konflikt.
     *  - **Widerspruch ist ein Fehler, keine Wahl.** Zwei verschiedene Zahlungsziele in
     *    einer Datei sind kein Datensatz, sondern ein Rätsel — die betroffene Kondition
     *    wird abgelehnt und benannt (Regel 3 auf Datei-Ebene). Die übrigen bleiben gültig:
     *    ein widersprüchlicher Bonus soll nicht den Mindestbestellwert mitreißen.
     *  - **Kein zweiter Schreibweg.** {@see SupplierService::updateConditions} trägt die
     *    D1-Prüfung und die Spalten-Whitelist. Ein geerbter Katalog-Lieferant wird darum
     *    **übersprungen mit Grund** statt verändert — und weil der Bestand fast nur
     *    geerbte Lieferanten kennt, ist das der häufige Fall und keine Randnotiz.
     *  - **Nur echte Änderung schreibt** (Regel 1). Steht in der Datei, was schon am
     *    Lieferanten steht, passiert nichts — der zweite Lauf ist auch hier ein No-op.
     *
     * @param  list<array{nr: int, werte: array<string, string>}>  $zeilen
     * @return array{status: ?string, grund?: string, gesetzt: array<string, mixed>, unveraendert: list<string>, abgelehnt: list<string>}
     */
    private function verarbeiteKonditionen(Team $team, FoodAlchemistSupplier $supplier, array $zeilen, bool $apply): array
    {
        $ergebnis = ['status' => null, 'gesetzt' => [], 'unveraendert' => [], 'abgelehnt' => []];

        /** @var array<string, array{wert: float|int, zeile: int, roh: string}> $gefunden */
        $gefunden = [];
        $konflikt = [];
        foreach ($zeilen as $zeile) {
            foreach (self::KONDITIONS_FELDER as $spalte => [$label, $typ, $_aliase]) {
                $roh = trim((string) ($zeile['werte']["lb:{$spalte}"] ?? ''));
                // Leere Zelle heißt „steht nicht da" (Regel 1). Eine einmal verworfene
                // Kondition bleibt verworfen — sonst hinge das Ergebnis an der
                // Zeilenreihenfolge, und dieselbe Datei ergäbe je nach Sortierung
                // einen anderen Lieferanten-Stammsatz.
                if ($roh === '' || isset($konflikt[$spalte])) {
                    continue;
                }
                $wert = $this->konditionsWert($roh, $typ);
                if ($wert === null) {
                    // Unlesbar zählt wie ein Widerspruch: die Spalte ist als Ganzes
                    // nicht vertrauenswürdig. Ein schon gefundener guter Wert fällt
                    // deshalb mit — auch wenn er weiter oben stand.
                    $ergebnis['abgelehnt'][] = "{$label} (Zeile {$zeile['nr']}): [{$roh}] " . $this->konditionsBand($typ);
                    unset($gefunden[$spalte]);
                    $konflikt[$spalte] = true;

                    continue;
                }
                if (! isset($gefunden[$spalte])) {
                    $gefunden[$spalte] = ['wert' => $wert, 'zeile' => $zeile['nr'], 'roh' => $roh];

                    continue;
                }
                if (abs((float) $gefunden[$spalte]['wert'] - (float) $wert) >= 0.0005) {
                    $ergebnis['abgelehnt'][] = "{$label}: Zeile {$gefunden[$spalte]['zeile']} sagt [{$gefunden[$spalte]['roh']}], "
                        . "Zeile {$zeile['nr']} sagt [{$roh}] — eine Kondition gilt dem Lieferanten, nicht der Zeile.";
                    unset($gefunden[$spalte]);
                    $konflikt[$spalte] = true;
                }
            }
        }

        if ($gefunden === []) {
            $ergebnis['status'] = $ergebnis['abgelehnt'] === [] ? null : 'fehler';

            return $ergebnis;
        }

        // Nur echte Änderung schreibt. `rebate_pct` & Co. kommen als decimal-Cast
        // (String „3.50") zurück — darum numerisch vergleichen, nicht per !==.
        $daten = [];
        foreach ($gefunden as $spalte => $treffer) {
            $alt = $supplier->getAttribute($spalte);
            if ($alt !== null && abs((float) $alt - (float) $treffer['wert']) < 0.0005) {
                $ergebnis['unveraendert'][] = self::KONDITIONS_FELDER[$spalte][0];

                continue;
            }
            $daten[$spalte] = $treffer['wert'];
            $ergebnis['gesetzt'][self::KONDITIONS_FELDER[$spalte][0]] = $treffer['wert'];
        }

        if ($daten === []) {
            $ergebnis['status'] = $ergebnis['abgelehnt'] === [] ? 'unveraendert' : 'fehler';

            return $ergebnis;
        }

        // D1 vorab prüfen, damit der Trockenlauf dieselbe Aussage trifft wie der scharfe
        // Lauf — die Ausnahme aus `updateConditions` käme sonst erst mit `--apply`.
        if (! $supplier->isOwnedBy($team)) {
            $ergebnis['status'] = 'uebersprungen';
            $ergebnis['grund'] = "Lieferant #{$supplier->id} gehört Team {$supplier->team_id} (geerbt) — "
                . 'Konditionen pflegt nur das Besitzer-Team (D1).';

            return $ergebnis;
        }

        if ($apply) {
            try {
                $this->lieferanten->updateConditions($team, (int) $supplier->id, $daten);
            } catch (\RuntimeException $e) {
                $ergebnis['status'] = 'fehler';
                $ergebnis['grund'] = $e->getMessage();
                $ergebnis['gesetzt'] = [];

                return $ergebnis;
            }
        }

        $ergebnis['status'] = $ergebnis['abgelehnt'] === [] ? 'geschrieben' : 'teilweise';

        return $ergebnis;
    }

    /**
     * Konditions-Zelle → Wert, oder `null` wenn unlesbar bzw. außerhalb des Bandes.
     *
     * Die Bänder sind Tippfehler-Schutz, keine Fach-Grenzen: ein Bonus von 150 % und ein
     * Zahlungsziel von 4000 Tagen sind keine Konditionen. **0 ist überall gültig** —
     * „frei Haus ab 0 €" und „kein Mindestbestellwert" sind echte Aussagen (anders als
     * beim Preis, wo die 0 ein aktiver Null-EK wäre).
     */
    private function konditionsWert(string $roh, string $typ): int|float|null
    {
        if ($typ === 'tage') {
            // Streng geparst statt Wörter wegzustreichen: „30/2 %" (Skonto) würde sonst
            // als 302 Tage durchgehen. Erlaubt ist Zahl + optionale Tage-Einheit.
            if (! preg_match('/^(?:netto\s*)?(\d{1,5})\s*(?:t|tg|td|tage?|days?)?\.?$/i', trim($roh), $m)) {
                return null;
            }
            $tage = (int) $m[1];

            return $tage <= self::MAX_ZAHLUNGSZIEL_TAGE ? $tage : null;
        }

        $z = $this->zahl($roh);
        if ($z === null || $z < 0) {
            return null;
        }
        if ($typ === 'prozent' && $z > 100) {
            return null;
        }

        return $z;
    }

    /** Klartext des zulässigen Bandes — steht im Bericht hinter einem abgelehnten Wert. */
    private function konditionsBand(string $typ): string
    {
        return match ($typ) {
            'tage' => 'ist keine Tages-Angabe zwischen 0 und ' . self::MAX_ZAHLUNGSZIEL_TAGE . ' — nicht gesetzt.',
            'prozent' => 'ist kein Prozentwert zwischen 0 und 100 — nicht gesetzt.',
            default => 'ist kein Betrag ≥ 0 — nicht gesetzt.',
        };
    }

    /** Datenblatt-Wort → GL-01-Wert. `null` = unlesbar (⇒ Warnung, Wert bleibt wie er war). */
    private function allergenWert(string $roh): ?string
    {
        $s = $this->normHeader($roh);
        if ($s === '' && trim($roh) === '-') {
            $s = 'nein';   // der Strich im Datenblatt heißt „nicht enthalten", nicht „leer"
        }

        return match (true) {
            in_array($s, ['ja', 'j', 'x', '1', 'yes', 'enthalten', 'enthaelt', 'positiv'], true) => 'enthalten',
            in_array($s, ['spuren', 'spur', 'kannspurenenthalten', 'kannspuren', 'traces', 'moeglich'], true) => 'spuren',
            in_array($s, ['nein', 'n', '0', 'no', 'nichtenthalten', 'frei', 'ohne', 'negativ'], true) => 'nicht_enthalten',
            in_array($s, ['unbekannt', 'keineangabe', 'ka', 'unknown'], true) => 'unbekannt',
            default => null,
        };
    }

    /** Datenblatt-Wort → GL-09-Wert (`ja`|`nein`|`unbekannt`). */
    private function deklarationsWert(string $roh): ?string
    {
        $s = $this->normHeader($roh);
        if (in_array($s, ['unbekannt', 'keineangabe', 'ka', 'unknown'], true)) {
            return 'unbekannt';
        }
        $b = $this->wahrheit($roh);

        return $b === null ? null : ($b ? 'ja' : 'nein');
    }

    /** Preis-Status der Datei → GL-11-Code: `0` Standard-EK, `2` Aktion. */
    private function preisStatus(string $roh): ?string
    {
        $s = mb_strtolower(trim(str_replace('-', '', $roh)));

        return match (true) {
            in_array($s, ['0', 'standard', 'standardek', 'ek', 'listenpreis', 'normal'], true) => '0',
            in_array($s, ['2', 'aktion', 'promo', 'aktionspreis', 'sonderpreis'], true) => '2',
            default => null,
        };
    }

    /**
     * E4 — die Post-Import-Kette. Ein bewegter Artikel wandert über die GP-Struktur in
     * die Rezept-Aggregate und von dort ins Marge-Signal; passiert das nicht, liegt der
     * neue Stand am Artikel, während Kalkulation und Cockpit den alten zeigen (genau die
     * „stille Drift" der DoD). **Bewegt** heißt seit S1c nicht nur „neuer Preis", sondern
     * auch „neuer Nährwert / neues Allergen / neue Deklaration": diese drei sind am
     * Rezept aggregierte Spalten, kein Live-Join (s. `RecipeRecomputeService::recomputePipeline`).
     *
     * Bewusst über die **LA↔GP-Struktur**, nicht über `lead_la_supplier_item_id`: die
     * T3-Kaskade in {@see RecipeRecomputeService} nimmt den Lead nur als *bevorzugten*
     * Kandidaten und fällt sonst auf den Mittelwert aller aktiven LAs zurück — ein
     * Nicht-Lead-Preis bewegt die Kosten also ebenfalls.
     *
     * Der Marge-Detektor wird **nicht** nachgebaut, sondern einmal am Ende aufgerufen:
     * er sucht sich frische Preisänderungen selbst (Lookback + Dedup je neuem Preis),
     * und eine zweite Schwellen-Logik im Importer wäre eine zweite Wahrheit.
     *
     * @param  array<int, true>  $bewegteItems
     * @return array{bewegt: int, gps: int, rezepte: int, neu_berechnet: int, signale: int}
     */
    public function postImportKette(Team $team, array $bewegteItems, bool $apply): array
    {
        $itemIds = array_keys($bewegteItems);
        $leer = ['bewegt' => count($itemIds), 'gps' => 0, 'rezepte' => 0, 'neu_berechnet' => 0, 'signale' => 0];
        if ($itemIds === []) {
            return $leer;
        }

        $gpIds = DB::table('foodalchemist_supplier_item_structures')
            ->whereIn('supplier_item_id', $itemIds)->whereNull('deleted_at')
            ->whereNotNull('gp_id')->distinct()->pluck('gp_id')->map(fn ($v) => (int) $v)->all();
        if ($gpIds === []) {
            return $leer;   // Artikel ohne GP-Struktur: der Preis steht, kostet aber (noch) kein Rezept
        }

        $recipeIds = DB::table('foodalchemist_recipe_ingredients')
            ->whereIn('gp_id', $gpIds)->whereNull('deleted_at')
            ->distinct()->pluck('recipe_id')->map(fn ($v) => (int) $v)->all();

        $kette = $leer;
        $kette['gps'] = count($gpIds);
        $kette['rezepte'] = count($recipeIds);
        if (! $apply) {
            return $kette;   // Vorschau: WAS getroffen würde, ohne zu rechnen
        }

        // V-049: EIN Lauf über die ganze Menge — die betroffene Vereinigung (Direktnutzer +
        // transitive Eltern) wird einmal aufgelöst, jedes Rezept genau einmal gerechnet.
        // `neu_berechnet` zählt deshalb die ganze gerechnete Menge inkl. Eltern, nicht mehr
        // nur die Direktnutzer; Einzelfehler stehen wie bisher im Log (I8).
        try {
            $kette['neu_berechnet'] = count($this->recompute->recomputeMany($recipeIds));
        } catch (\Throwable $e) {
            Log::warning("Kanal-B-Kette: Recompute der bewegten Menge fehlgeschlagen — {$e->getMessage()}");
        }

        try {
            // V-050: der Detektor bekommt die bewegte GP-Menge statt der Team-weiten Suche.
            // Deckel = Größe der eigenen Menge, damit er hier nie bindet — eine Kappung wäre
            // in genau dieser Kette die stille Auslassung, gegen die E4 geschrieben ist.
            $kette['signale'] = app(SignalDetektorService::class)
                ->preisSprungMargeImpact($team, maxGps: max(count($gpIds), 1), gpIds: $gpIds);
        } catch (\Throwable $e) {
            Log::warning("Kanal-B-Kette: Preis-Sprung-Detektor fehlgeschlagen — {$e->getMessage()}");
        }

        return $kette;
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
        foreach (self::PREIS_FELDER as $kanon => $aliase) {
            if ($norm === $kanon || in_array($norm, $aliase, true)) {
                return $kanon;
            }
        }
        // S2: eigener Namensraum `lb:` (wie `nw:`/`al:`/`dk:` bei den Detail-Blöcken) —
        // das Ziel ist der Lieferant, nicht der Artikel, und `mappeWerte` läuft nur über
        // FELDER. Damit kann eine Konditions-Spalte nie in einer Artikel-Spalte landen.
        foreach (self::KONDITIONS_FELDER as $spalte => [$_label, $_typ, $aliase]) {
            if (in_array($norm, $aliase, true)) {
                return "lb:{$spalte}";
            }
        }

        return null;
    }

    private function spaetereStufe(string $norm): ?string
    {
        return self::SPAETER[$norm] ?? null;
    }

    /**
     * Detail-Spalte (S1c) → kanonischer Schlüssel `nw:…` / `al:…` / `dk:…`.
     *
     * Zwei Rückgaben, die auseinandergehalten werden müssen: `null` heißt „keine
     * Detail-Spalte" (der Aufrufer sucht weiter), ein `false` im zweiten Parameter
     * heißt „Detail-Spalte, aber kein Ziel" — die gehört gemeldet und nicht unter
     * „nicht Teil der Vorlage" begraben, sonst liest der Bediener eine bewusste
     * Grenze (nur 8 Kernwerte) als Tippfehler in seiner Kopfzeile.
     */
    private function detailSpalte(string $norm, ?string &$hinweis = null): ?string
    {
        $hinweis = null;
        // ALLE passenden Präfixe durchprobieren, nicht nur das erste: „Nährwert Eiweiß"
        // normalisiert zu `naehrwerteiweiss` und trifft damit sowohl `naehrwerte` (Rest
        // „iweiss", kein Ziel) als auch `naehrwert` (Rest „eiweiss", Treffer). Ein früher
        // Abbruch beim ersten Präfix hätte die halbe Vorlage verschluckt.
        foreach (self::DETAIL_PRAEFIXE as $praefix => [$kuerzel, $klartext]) {
            if (! str_starts_with($norm, $praefix) || $norm === $praefix) {
                continue;
            }
            $rest = substr($norm, strlen($praefix));
            $ziele = match ($kuerzel) {
                'nw' => self::NAEHRWERT_SPALTEN,
                'al' => self::ALLERGEN_SPALTEN,
                default => self::DEKLARATION_SPALTEN,
            };
            foreach ($ziele as $key => $aliase) {
                if ($rest === $key || in_array($rest, $aliase, true)) {
                    return "{$kuerzel}:{$key}";
                }
            }
            if ($kuerzel === 'nw' && in_array($rest, self::NAEHRWERT_SALZ, true)) {
                return 'nw:__salz';
            }
            $hinweis ??= "{$klartext}-Spalte ohne Ziel — bekannt sind: " . implode(', ', array_keys($ziele));
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
     * Ist an dieser Zeile überhaupt etwas passiert? Eine unveränderte Zeile ohne Preis-
     * und ohne Detail-Bewegung ist der **Normalfall** eines Wiederholungslaufs und damit
     * Rauschen — sie wird weder in der Konsole noch in der MCP-Vorschau ausgegeben.
     *
     * Bewusst hier und nicht zweimal beim Leser: was ein Ereignis ist, entscheidet der
     * Bericht-Erzeuger, sonst driften Konsolen- und Tool-Ausgabe auseinander.
     *
     * @param array<string, mixed> $befund
     */
    public static function istEreignis(array $befund): bool
    {
        if (($befund['status'] ?? null) !== 'unveraendert') {
            return true;
        }
        if (isset($befund['preis']['status']) && $befund['preis']['status'] !== 'unveraendert') {
            return true;
        }

        return array_filter($befund['details'] ?? []) !== [];
    }

    /**
     * Lauf-Bookkeeping in `foodalchemist_bulk_runs` (Typ `ingest`) — dieselbe Tabelle
     * wie Anreicherungs- und Review-Läufe, damit „welche Läufe sind gelaufen?" eine
     * Antwort hat und nicht drei. Grundlage für S3 `ingest.STATUS`.
     */
    public function starteRun(int $teamId, int $total, ?int $userId = null, array $context = []): int
    {
        // S3b: wer ausgelöst hat, steckt in `user_id`. Beim Kommando bleibt es NULL (die
        // Konsole hat keinen Benutzer), über MCP steht es drin — der Trigger ist
        // ausdrücklich ein menschlich angestoßener Vorgang, und das soll am Lauf ablesbar
        // sein. H3a ergänzt WORAN (V-047): Datei + Lieferant liegen im `context`.
        return (int) FoodAlchemistBulkRun::starte($teamId, BulkRunType::Ingest, $total, $context, $userId)->id;
    }

    /**
     * Eine Zeile mit Preis-Fehler zählt als **fehlgeschlagen**, auch wenn ihr
     * Artikel-Stamm geschrieben wurde: für den Bediener ist „EK nicht angekommen"
     * kein Teilerfolg. Die beiden Mengen sind disjunkt — eine Zeile mit Zeilen-Fehler
     * kommt gar nicht bis zum Preis —, `done + failed` bleibt also die Zeilenzahl.
     *
     * @param array<string, mixed> $bericht
     */
    public function beendeRun(int $runId, array $bericht): void
    {
        $preisFehler = (int) ($bericht['preise']['fehler'] ?? 0);
        $done = (int) $bericht['neu'] + (int) $bericht['aktualisiert'] + (int) $bericht['unveraendert']
            + (int) $bericht['uebersprungen'] - $preisFehler;
        $failed = (int) $bericht['fehler'] + $preisFehler;
        FoodAlchemistBulkRun::whereKey($runId)->update([
            'status' => BulkRunStatus::Done->value,
            // S3a: `total` wird hier nachgetragen, nicht bei starteRun — die Zeilenzahl kennt
            // erst der Reader. Ohne das bliebe sie auf 0 stehen und `ingest.STATUS` meldete
            // strukturell „0 Zeilen, n verarbeitet".
            'total' => $done + $failed,
            'done' => $done,
            'failed' => $failed,
            'updated_at' => now(),
        ]);
    }
}
