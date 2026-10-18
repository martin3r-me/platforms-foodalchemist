<?php

namespace Platform\FoodAlchemist\Services\Necta;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Enums\SignalSeverity;
use Platform\FoodAlchemist\Enums\SignalTyp;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\Produktpass\ProduktpassAbweichungService;
use Platform\FoodAlchemist\Services\SignalService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\ProduktpassSchutz;
use Symfony\Component\Uid\UuidV7;

/**
 * Necta-Abgleich · nächtlich + wöchentlich (Bauauftrag 15.10.2026, PR 2).
 *
 * Nachts (Standard): je aktivem Lieferanten das Preis-Delta (`supplier-item-prices`, supplierId + changeDateFrom = letzter
 * erfolgreicher Lauf − 1 Tag). Unbekannte Artikel aus dem Delta werden wie im Nachholer angelegt (samt Details/Preisen).
 *  - Preise kommen immer aus Necta: bekannte Zeile mit geänderten Werten → alter Stand nach `price_history`, dann Update;
 *    neue Zeile → Insert, beim Vorgänger (gleicher Artikel, Team und Status, offen) `valid_to` = `status_valid_from` der neuen
 *    Zeile fortschreiben (ebenfalls mit History). Nie löschen.
 *
 * Wöchentlich (`voll`): zusätzlich alle Artikel, Allergene, Deklarationen und Nährwerte aktiver Lieferanten vergleichen.
 *  - Erster Abgleich eines Objekts (noch kein `necta_stand`): Abweichungen übernehmen — der Stamm ist frisch importiert und noch
 *    nicht von Hand gepflegt (Entscheid Dominique über den Kurator, 15.10.).
 *  - Ab dem zweiten Lauf: FA-Wert ≠ letzter Necta-Stand → von Hand gepflegt → Prüffall `manuell_gepflegt`, FA bleibt.
 *  - Produktpass (Vorrang, Entscheid Dominique 16.10.2026): Felder mit Produktpass-Marker (`foodalchemist_produktpass_felder`,
 *    {@see ProduktpassSchutz}, nur Quelle Dokument) in den Kerngruppen (Allergene samt Unterarten, Deklaration, Nährwerte, Zutatenliste,
 *    Gebinde) behalten den
 *    Pass-Wert, auch gegen ein strengeres Necta: Abweichung → Prüffall `produktpass_abweichung` + EIN Signal je Artikel
 *    ({@see ProduktpassAbweichungService}). Übrige Pass-Felder kommen aus Necta; nur ein leerer Necta-Wert löscht sie nicht.
 *  - Kennzeichnung (Allergene, Deklaration/Zusatzstoffe) nach Strenge (Entscheid Dominique 15.10.2026, Lauf 6 auf io:
 *    18.491 Allergen-Abweichungen, Lieferanten haben seit dem Juni-Export nachgepflegt): Necta STRENGER als FA → automatisch
 *    übernehmen, auch bei Handpflege (Zähler `allergen_verschaerft` / `deklaration_verschaerft`); Necta LOCKERER → Prüffall,
 *    FA bleibt, auch wenn FA dem letzten Necta-Stand entspricht — nie still lockern. Gleich streng (z. B. Deklaration 0 ⇄ NULL,
 *    beides k.A.) → Necta-Wert übernehmen, ohne Prüffall. Allergen-Unterarten (`details`: wheat, oats, almond …) dieselbe Regel
 *    JE UNTERART (Prüffall-Feld `details.<unterart>`). Ausnahme (Entscheid Dominique 15.10., Lauf 8: 36.057 × unbekannt →
 *    nicht_enthalten): die ausdrückliche Erklärung der Abwesenheit (Allergen/Unterart NULL → nicht_enthalten, Deklaration
 *    0/NULL → 1) wird automatisch übernommen (`allergen_abwesenheit_erklaert` / `deklaration_abwesenheit_erklaert`), aber nur,
 *    wenn FA nicht von Hand gepflegt ist (kein Stand oder FA = Stand); das Format ist gemessen identisch mit dem Bestand (139.012 von 139.012
 *    Zeilen der demo-Kopie gegen die WaWi-Rohcodes, 15.10.2026).
 *  - Diagnose im Lauf: Prüffälle je Feld (`pruefaelle_je_feld`) und Lockerungen je Wechsel (`lockerung_je_wechsel`,
 *    z. B. „unbekannt→nicht_enthalten“), damit ein Probelauf zeigt, woraus die Prüffälle bestehen.
 *  - Ein Artikel, den wir haben, Necta aber nicht mehr liefert, wird NICHT gelöscht → Prüffall `in_necta_entfernt`. Ausgenommen
 *    sind Artikel, die nie aus Necta kamen ({@see self::KUNST_ID_AB}).
 *  - Fehlende Detailzeilen zu bekannten Artikeln werden angelegt.
 *
 * Inaktive bzw. ausgenommene Lieferanten werden nicht abgeglichen. Bei Fehlschlag: Signal `necta_abgleich_fehler` an den Master.
 * Probelauf schreibt nur die Laufzeile; er endet mit der Zahl der Prüffälle je Art.
 */
class NectaAbgleichService extends NectaLaufBasis
{
    /** Objekte, deren Abweichungen nie still übernommen werden (Kennzeichnung). */
    public const KENNZEICHNUNG = ['item_allergen', 'item_declaration'];

    /** @var array<int,true> Artikel-IDs, an denen dieser Lauf eine neue Pass-Abweichung gefunden hat */
    private array $passSignalArtikel = [];

    /**
     * Ab dieser `legacy_id` stammt ein Artikel nicht aus Necta: Das Skript `00_SYSTEM/00.04_Scripts/114_add_hanos_achenbach.py`
     * (+ `114b_pdf_diff_achenbach.py`) legte Hanos-/Achenbach-Excel/PDF-Artikel in der WaWi-Kopie mit `NEW_ITEM_ID_START =
     * 90_000_000` an („sicherer Range außerhalb existierender supplier_items.id“); der FA-Import übernahm `supplier_items.id` als
     * `legacy_id`. In der WaWi-Kopie genau 64 Artikel 90.000.000–90.000.063 (Achenbach 52, Hanos 9, Chefs Culinar 2,
     * Bergischländer 1) = die 64 `in_necta_entfernt` aus Lauf 6; höchste echte Necta-ID dort 35.954.031.
     * Ausgeschlossen wird nur, wer zusätzlich keinen Necta-Stand hat — ein echter Necta-Artikel in diesem Bereich (sobald Necta so
     * hoch zählt) hat einen Stand und wird weiter gemeldet.
     */
    public const KUNST_ID_AB = 90_000_000;

    /** Strenge je Wert, höher = strenger. Allergene: enthalten > spuren > unbekannt (NULL) > nicht_enthalten. */
    private const ALLERGEN_STRENGE = ['enthalten' => 3, 'spuren' => 2, '' => 1, 'nicht_enthalten' => 0];

    /** Preis-Spalten, die Necta pflegt und die wir vergleichen. */
    private const PREIS_SPALTEN = ['status', 'price', 'price_partial', 'note', 'valid_to', 'status_valid_from', 'is_blocked', 'change_date'];

    private ?int $teamId = null;

    /**
     * @param  array{team_id?: ?int, probe?: bool, voll?: bool, seit?: ?string, lieferanten?: list<int>, ohne_lieferanten?: list<int>,
     *               seite?: int, pause_ms?: int, detail_schwelle?: int, user_id?: ?int}  $opt
     * @param  callable(string): void|null  $log
     * @return array{lauf_id: int, status: string, zaehler: array<string,mixed>}
     */
    public function lauf(int $verbindungId, array $opt = [], ?callable $log = null, ?NectaQuelle $quelle = null): array
    {
        $this->vorbereiten($verbindungId, $opt, $quelle);
        $log ??= fn (string $z) => null;
        $this->teamId = $opt['team_id'] ?? MasterTeam::id();
        $voll = (bool) ($opt['voll'] ?? false);
        $seit = $opt['seit'] ?? $this->seitLetztemLauf($verbindungId);
        $this->opt['seit'] = $seit;
        $this->zaehler += ['seit' => $seit, 'preise_geprueft' => 0, 'preise_aktualisiert' => 0, 'vorgaenger_fortgeschrieben' => 0,
            'felder_uebernommen' => 0, 'objekte_aktualisiert' => 0, 'pruefaelle' => [], 'in_necta_entfernt' => 0,
            'allergen_verschaerft' => 0, 'deklaration_verschaerft' => 0, 'unterart_verschaerft' => 0, 'nicht_aus_necta' => 0,
            'allergen_abwesenheit_erklaert' => 0, 'deklaration_abwesenheit_erklaert' => 0,
            'produktpass_abweichung_neu' => 0, 'produktpass_behalten' => 0, 'produktpass_necta_gewinnt' => 0,
            'pruefaelle_je_feld' => [], 'lockerung_je_wechsel' => []];

        $this->laufId = $this->laufAnlegen($voll ? 'vollabgleich' : 'abgleich', $verbindungId, $this->teamId, $opt);
        if ($voll && ! $this->probe && empty($opt['erzwingen']) && ($grund = $this->vollGesperrt($verbindungId)) !== null) {
            $this->zaehler['grund'] = $grund;
            $this->laufBeenden('ausgesetzt');
            $log("Vollvergleich ausgesetzt: {$grund}");

            return ['lauf_id' => $this->laufId, 'status' => 'ausgesetzt', 'zaehler' => $this->zaehler];
        }

        try {
            foreach ($this->lieferanten($log) as $index => $l) {
                $this->preisDelta($l, $seit, $log);
                if ($voll) {
                    $this->vollvergleich($l, $log);
                }
                $this->zaehler['lieferanten_erledigt']++;
                $this->cursor($index + 1);
            }
            if (! $this->probe) {
                $this->passSignaleSchreiben();
            }
            $this->rezepteNachrechnen($log);
            $this->laufBeenden('fertig');
        } catch (\Throwable $e) {
            $this->laufBeenden('fehler', $e->getMessage());
            if (! $this->probe) {
                $this->signalFehler($verbindungId, $e->getMessage());
            }
            throw $e;
        }

        return ['lauf_id' => $this->laufId, 'status' => 'fertig', 'zaehler' => $this->zaehler];
    }

    /**
     * Ein Vollvergleich läuft nur, wenn der nächtliche Lauf seit dem letzten Vollvergleich mindestens einmal erfolgreich war (sonst
     * vergleicht er auf veralteten Preisen und überdeckt einen kaputten Nachtlauf). Rückgabe: Grund oder null.
     */
    private function vollGesperrt(int $verbindungId): ?string
    {
        $basis = DB::table('foodalchemist_necta_laeufe')->where('verbindung_id', $verbindungId)->where('probe', false)->where('status', 'fertig');
        $letzterVoll = (clone $basis)->where('art', 'vollabgleich')->max('gestartet_at');
        $nachtNachher = (clone $basis)->where('art', 'abgleich')->when($letzterVoll !== null, fn ($q) => $q->where('gestartet_at', '>', $letzterVoll))->exists();

        return $nachtNachher ? null : ($letzterVoll === null
            ? 'noch kein erfolgreicher nächtlicher Abgleich'
            : 'seit dem letzten Vollvergleich kein erfolgreicher nächtlicher Abgleich');
    }

    /**
     * changeDateFrom = Start des letzten erfolgreichen echten Laufs dieser Verbindung − 1 Tag (Überlappung). Ohne Vorlauf: 2 Tage.
     * Format ISO ohne Zone ('Y-m-d\TH:i:s'), so von Necta angenommen (Kurator 15.10.). In welcher Zone Necta den Filter auswertet,
     * ist UNGEPRÜFT — der eine Tag Überlappung deckt das ab; doppelt gelesene Zeilen sind über legacy_id harmlos.
     */
    private function seitLetztemLauf(int $verbindungId): string
    {
        $letzter = DB::table('foodalchemist_necta_laeufe')->where('verbindung_id', $verbindungId)->where('probe', false)
            ->where('status', 'fertig')->whereIn('art', ['abgleich', 'vollabgleich', 'nachholen'])->max('gestartet_at');

        return ($letzter !== null ? \Illuminate\Support\Carbon::parse($letzter)->subDay() : now()->subDays(2))->format('Y-m-d\TH:i:s');
    }

    // ── Nachts: Preis-Delta ───────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array{legacy_id:int, name:string, fa_id:?int} $l */
    private function preisDelta(array $l, string $seit, callable $log): void
    {
        $zeilen = $this->alleSeiten('supplier-item-prices', ['supplierId' => $l['legacy_id'], 'changeDateFrom' => $seit]);
        if ($zeilen === []) {
            return;
        }
        $this->zaehler['preise_geprueft'] += count($zeilen);
        $artikelIds = array_values(array_unique(array_map(fn ($r) => (int) $r['supplierItemId'], $zeilen)));
        $bekannt = DB::table('foodalchemist_supplier_items')->whereIn('legacy_id', $artikelIds)->pluck('id', 'legacy_id')->all();

        // Unbekannte Artikel aus dem Delta: Lieferanten-Artikel durchgehen, bis alle gefunden sind (Raw-API filtert Artikel nur je Lieferant)
        $fehlend = array_flip(array_diff($artikelIds, array_map('intval', array_keys($bekannt))));
        $neu = [];
        if ($fehlend !== []) {
            $neu = [];
            foreach ($this->alleSeitenIter('supplier-items', ['supplierId' => $l['legacy_id']]) as $r) {
                if (isset($fehlend[(int) $r['id']])) {
                    $neu[(int) $r['id']] = $this->artikelAnlegen($l, $r);
                    unset($fehlend[(int) $r['id']]);
                    if ($fehlend === []) {
                        break;
                    }
                }
            }
            $this->neueArtikelAbschliessen($l, $neu, $this->teamId, $log);   // legt auch deren Preise an
            if ($fehlend !== []) {
                $this->zaehler['warnungen'][] = sprintf('%s: %d Preis-Artikel nicht in der Artikelliste gefunden.', $l['name'], count($fehlend));
            }
        }

        foreach ($zeilen as $r) {
            if (isset($neu[(int) $r['supplierItemId']])) {
                continue;   // neue Artikel: Preise kamen schon mit der Anlage (sonst doppelt gezählt)
            }
            $faArtikel = $bekannt[(int) $r['supplierItemId']] ?? null;
            if ($faArtikel !== null) {
                $this->preisAbgleichen($r, (int) $faArtikel);
            }
        }
    }

    private function preisAbgleichen(array $r, int $faArtikelId): void
    {
        $neu = $this->mapper->preis($r);
        $alt = DB::table('foodalchemist_prices')->where('legacy_id', $neu['legacy_id'])->first();
        if ($alt !== null) {
            $diff = array_filter(self::PREIS_SPALTEN, fn ($s) => self::norm($alt->{$s} ?? null) !== self::norm($neu[$s] ?? null));
            if ($diff === []) {
                return;
            }
            $this->zaehler['preise_aktualisiert']++;
            $this->beispiel('preise_aktualisiert', ['legacy_id' => $neu['legacy_id'], 'felder' => array_values($diff), 'price' => $neu['price']]);
            if (! $this->probe) {
                $this->history($alt);
                DB::table('foodalchemist_prices')->where('id', $alt->id)->update(array_intersect_key($neu, array_flip(self::PREIS_SPALTEN)) + ['updated_at' => now()]);
            }
            // Necta stellt Zeilen um (Preis → läuft aus → eingestellt): Lieferstatus neu ableiten
            $this->lieferstatusZaehlen(app(NectaLieferstatusService::class)->aktualisieren($faArtikelId, $this->teamId, $this->probe, $this->laufId));
            if (! $this->probe) {
                app(DarreichungService::class)->nachLaPreisAenderung($faArtikelId);   // Zubehör (Non-Food) an Darreichungen: gezielt neu rechnen (nach dem Lieferstatus)
            }

            return;
        }

        // neue Necta-Preiszeile → Insert; offene Vorgänger desselben Artikels/Teams/Status bekommen valid_to
        $this->zaehler['preise_neu']++;
        $this->beispiel('preise', $neu);
        $vorgaenger = DB::table('foodalchemist_prices')->where('supplier_item_id', $faArtikelId)
            ->where('team_id', $this->teamId)->where('status', $neu['status'])->whereNull('valid_to')->whereNull('deleted_at')
            ->when($neu['status_valid_from'] !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('status_valid_from')
                ->orWhere('status_valid_from', '<', $neu['status_valid_from'])))
            ->get();
        $this->zaehler['vorgaenger_fortgeschrieben'] += $vorgaenger->count();
        if ($this->probe) {
            return;
        }
        $this->einfuegen('foodalchemist_prices', $neu + ['supplier_item_id' => $faArtikelId, 'team_id' => $this->teamId]);
        foreach ($vorgaenger as $v) {
            $this->history($v);
            DB::table('foodalchemist_prices')->where('id', $v->id)->update([
                'valid_to' => $neu['status_valid_from'] ?? $neu['change_date'] ?? now()->toDateTimeString(), 'updated_at' => now(),
            ]);
        }
        // Lieferstatus neu ableiten; bei „eingestellt“ enden auch offene Zeilen ANDEREN Status und die Necta-Historie (Vorgänger
        // oben schließt nur gleichen Status: genau die Lücke, durch die Kluth LA 318750 trotz Eingestellt-Zeile „offen“ blieb)
        $this->lieferstatusZaehlen(app(NectaLieferstatusService::class)->aktualisieren($faArtikelId, $this->teamId, false, $this->laufId));
        app(DarreichungService::class)->nachLaPreisAenderung($faArtikelId);   // Zubehör (Non-Food) an Darreichungen: gezielt neu rechnen (nach dem Lieferstatus)
    }

    /** Unser Stand einer Preiszeile vor dem Update (Kostenverlauf). Nie löschen. */
    private function history(object $preis): void
    {
        $this->einfuegen('foodalchemist_price_history', [
            'price_id' => $preis->id, 'legacy_id' => $preis->legacy_id, 'supplier_item_id' => $preis->supplier_item_id,
            'team_id' => $preis->team_id, 'status' => $preis->status, 'price' => $preis->price, 'price_partial' => $preis->price_partial,
            'valid_to' => $preis->valid_to, 'status_valid_from' => $preis->status_valid_from, 'is_blocked' => $preis->is_blocked,
            'change_date' => $preis->change_date, 'quelle' => 'necta_abgleich', 'lauf_id' => $this->laufId, 'abgeloest_at' => now(),
        ]);
    }

    // ── Wöchentlich: Vollvergleich ────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Speicher (Server memory_limit 512M): streamt seitenweise — je Necta-Seite nur deren Zeilen und eine FA-Abfrage dazu. Über den
     * Lieferanten gehalten wird allein die Menge der gesehenen Necta-Artikel-IDs (int => true, je Lieferant, nicht global) für
     * „in_necta_entfernt“; der Gegencheck läuft in Blöcken zu 2.000 FA-Zeilen. Keine Liste über alle 348.000 Artikel.
     *
     * @param array{legacy_id:int, name:string, fa_id:?int} $l
     */
    private function vollvergleich(array $l, callable $log): void
    {
        // Artikel
        $gesehen = [];
        $neu = [];
        $seite = 1;
        do {
            $antwort = $this->holen('supplier-items', $seite, ['supplierId' => $l['legacy_id']]);
            $items = $antwort['items'];
            $this->zaehler['artikel_geprueft'] += count($items);
            $fa = DB::table('foodalchemist_supplier_items')->whereIn('legacy_id', array_map(fn ($r) => (int) $r['id'], $items))->get()->keyBy('legacy_id');
            foreach ($items as $r) {
                $legacy = (int) $r['id'];
                $gesehen[$legacy] = true;
                $zeile = $fa->get($legacy);
                if ($zeile === null) {
                    $neu[$legacy] ??= $this->artikelAnlegen($l, $r);

                    continue;
                }
                $this->vergleichen('supplier_item', 'foodalchemist_supplier_items', $zeile, $this->mapper->artikel($r));
            }
            $seite++;
        } while ($seite <= max(1, $antwort['totalPages']) && $items !== []);
        $this->neueArtikelAbschliessen($l, $neu, $this->teamId, $log);

        // Artikel, die wir haben, Necta aber nicht mehr liefert: nie löschen → Prüffall
        if ($l['fa_id'] !== null) {
            DB::table('foodalchemist_supplier_items')->where('supplier_id', $l['fa_id'])->whereNotNull('legacy_id')->whereNull('deleted_at')
                ->orderBy('id')->select(['id', 'legacy_id', 'designation'])->chunk(2000, function ($teil) use ($gesehen) {
                    // Kunst-IDs ohne Necta-Stand kamen nie aus Necta (Skript 114) → nicht „entfernt“, nur zählen
                    $mitStand = DB::table('foodalchemist_necta_stand')->where('objekt', 'supplier_item')
                        ->whereIn('legacy_id', $teil->pluck('legacy_id')->filter(fn ($id) => (int) $id >= self::KUNST_ID_AB)->all())
                        ->pluck('legacy_id')->flip();
                    foreach ($teil as $a) {
                        if ((int) $a->legacy_id >= self::KUNST_ID_AB && ! $mitStand->has($a->legacy_id)) {
                            $this->zaehler['nicht_aus_necta']++;

                            continue;
                        }
                        if (! isset($gesehen[(int) $a->legacy_id])) {
                            $this->zaehler['in_necta_entfernt']++;
                            $this->pruefall('in_necta_entfernt', 'supplier_item', (int) $a->legacy_id, null, (string) $a->designation, null);
                        }
                    }
                });
        }

        // Details bekannter Artikel, seitenweise: je Seite EINE Abfrage für die FA-Zeilen (nicht eine je Artikel);
        // die neuen Artikel hat neueArtikelAbschliessen schon angelegt
        $gesehenDetails = [];
        foreach (['allergene', 'deklaration', 'naehrwerte'] as $art) {
            $tabelle = $this->detailZiel($art, ['supplierItemId' => 0], null)[0];
            $seite = 1;
            do {
                $antwort = $this->holen(self::DETAIL_RESSOURCEN[$art], $seite, ['supplierId' => $l['legacy_id']]);
                $ids = array_map(fn ($r) => (int) ($r['supplierItemId'] ?? 0), $antwort['items']);
                // Schlüssel ist der FA-Artikel (Unique supplier_item_id), nicht legacy_id: Zeilen aus Handpflege/Produktpass haben keine
                // legacy_id, gelöschte sieht der Unique-Index trotzdem — beides führte 18.10. zum 1062-Absturz beim Einfügen
                $faArtikel = $ids === [] ? [] : DB::table('foodalchemist_supplier_items')->whereIn('legacy_id', $ids)->pluck('id', 'legacy_id')->all();
                $faZeilen = $faArtikel === [] ? collect() : DB::table($tabelle)->whereIn('supplier_item_id', array_values($faArtikel))->get()->keyBy('supplier_item_id');
                foreach ($antwort['items'] as $r) {
                    $artikelLegacy = (int) ($r['supplierItemId'] ?? 0);
                    if (isset($neu[$artikelLegacy]) || ! isset($faArtikel[$artikelLegacy])) {
                        continue;
                    }
                    $faId = (int) $faArtikel[$artikelLegacy];
                    if (isset($gesehenDetails[$art][$faId])) {
                        $this->zaehler['detail_doppelt_in_necta'] = ($this->zaehler['detail_doppelt_in_necta'] ?? 0) + 1;   // erster Necta-Datensatz gilt

                        continue;
                    }
                    $gesehenDetails[$art][$faId] = true;
                    [, $objekt, $necta] = $this->detailZiel($art, $r, null);
                    $fa = $faZeilen->get($faId);
                    if ($fa === null) {
                        $this->detailAnlegen($art, $r, $faId, null);

                        continue;
                    }
                    if ($fa->deleted_at !== null) {
                        $this->detailWiederbeleben($art, $tabelle, $objekt, $fa, $necta);

                        continue;
                    }
                    // Zeile ohne legacy_id oder mit Quelle manual kam nicht aus Necta: gilt ohne Necta-Stand als von Hand gepflegt (Kurator 18.10.)
                    $handzeile = $fa->legacy_id === null || ($fa->source ?? null) === 'manual';
                    if ($fa->legacy_id === null && ! $this->probe) {
                        DB::table($tabelle)->where('id', $fa->id)->update(['legacy_id' => $artikelLegacy]);   // Zuordnung nachtragen, Werte prüft vergleichen()
                    }
                    $this->vergleichen($objekt, $tabelle, $fa, $necta, $handzeile);
                }
                $seite++;
            } while ($seite <= max(1, $antwort['totalPages']) && $antwort['items'] !== []);
        }
    }

    /**
     * Gelöschte Detailzeile (Allergene/Deklaration/Nährwerte), die Necta wieder liefert: Der Unique-Index auf supplier_item_id lässt
     * keine zweite Zeile zu. Die gelöschte Zeile trägt keinen gültigen Stand mehr — sie wird mit den Necta-Werten belegt wie eine neue
     * (gleiche Regeln wie detailAnlegen: Necta ist Erstbefüllung, kein Abgleich gegen alte Werte).
     *
     * @param  array<string,mixed>  $necta  gemappte Necta-Werte (inkl. legacy_id)
     */
    private function detailWiederbeleben(string $art, string $tabelle, string $objekt, object $fa, array $necta): void
    {
        $this->zaehler["{$art}_wiederbelebt"] = ($this->zaehler["{$art}_wiederbelebt"] ?? 0) + 1;
        $this->beispiel("{$art}_wiederbelebt", ['supplier_item_id' => $fa->supplier_item_id, 'legacy_id' => $necta['legacy_id']]);
        $this->kennzeichnungMerken($objekt, (int) $fa->supplier_item_id);
        if ($this->probe) {
            return;
        }
        DB::table($tabelle)->where('id', $fa->id)->update([...$necta, 'deleted_at' => null, 'updated_at' => now()->toDateTimeString()]);
        $this->stand($objekt, (int) $necta['legacy_id'], $necta);
    }

    /**
     * Ein Objekt gegen Necta abgleichen (Regeln im Klassenkopf). Schreibt bei Übernahme die FA-Zeile und danach den Necta-Stand.
     *
     * @param  array<string,mixed>  $necta  gemappte Necta-Werte (inkl. legacy_id)
     */
    private function vergleichen(string $objekt, string $tabelle, object $fa, array $necta, bool $handzeile = false): void
    {
        $legacy = (int) $necta['legacy_id'];
        unset($necta['legacy_id']);
        $stand = $this->standLesen($objekt, $legacy);
        // Handzeile ohne Necta-Stand (Kurator 18.10.): strenger übernehmen bleibt (Sicherheit), lockerer Prüffall, alles andere
        // manuell_gepflegt statt „erster Abgleich übernimmt Necta“ — sonst würden handgepflegte Werte still überschrieben
        $handOhneStand = $handzeile && $stand === null;
        $kennzeichnung = in_array($objekt, self::KENNZEICHNUNG, true);
        $uebernehmen = [];
        foreach ($necta as $spalte => $nWert) {
            $n = self::norm($nWert);
            $f = self::norm($fa->{$spalte} ?? null);
            if ($n === $f || ($spalte === 'details' && ProduktpassSchutz::jsonNorm($nWert) === ProduktpassSchutz::jsonNorm($fa->{$spalte} ?? null))) {
                continue;   // details: gleiches JSON-Objekt (MySQL formatiert JSON-Spalten um)
            }
            if (ProduktpassSchutz::schuetzt($objekt, $fa, $spalte)) {
                if ($n === null || ($spalte === 'details' && ProduktpassSchutz::jsonNorm($nWert) === null)
                    || ($objekt === 'item_declaration' && $spalte !== 'details' && $n === '0')) {
                    continue;   // leerer Necta-Wert (k. A., Deklaration 0) löscht den Pass-Wert nie und ist keine Abweichung
                }
                if (ProduktpassSchutz::istKern($objekt, $spalte)) {
                    // Vorrang Produktpass (Entscheid Dominique 16.10.2026): Pass-Wert bleibt, auch wenn Necta strenger ist — keine
                    // Strenge-Regel, kein Automatismus; jede Abweichung wird Prüffall + Signal
                    $this->passAbweichung($objekt, $legacy, ProduktpassSchutz::itemId($objekt, $fa), $spalte, $fa->{$spalte} ?? null, $nWert);

                    continue;
                }
                $uebernehmen[$spalte] = $nWert;   // kein Kern: Necta gewinnt, auch über den Stand hinweg (Pass-Wert ist keine Handpflege)
                $this->zaehler['produktpass_necta_gewinnt']++;

                continue;
            }
            if ($kennzeichnung && $spalte === 'details' && $objekt === 'item_allergen') {
                $neu = $this->unterartenAbgleichen($legacy, $fa->{$spalte} ?? null, $nWert, $stand === null ? null : ($stand[$spalte] ?? null), $stand !== null, $handOhneStand);
                if (self::norm($neu) !== $f) {
                    $uebernehmen[$spalte] = $neu;
                }

                continue;
            }
            if ($kennzeichnung) {
                $strenge = $spalte === 'details' ? null : self::strenge($objekt, $nWert) <=> self::strenge($objekt, $fa->{$spalte} ?? null);
                $nichtVonHand = ($stand === null && ! $handOhneStand) || ($stand !== null && self::norm($stand[$spalte] ?? null) === $f);
                if ($strenge === -1 && $nichtVonHand && self::abwesenheitErklaert($objekt, $fa->{$spalte} ?? null, $nWert)) {
                    // Entscheid Dominique 15.10.: ausdrückliche Lieferanten-Erklärung der Abwesenheit (k.A. → nicht_enthalten / nein)
                    $uebernehmen[$spalte] = $nWert;
                    $this->zaehler[$objekt === 'item_allergen' ? 'allergen_abwesenheit_erklaert' : 'deklaration_abwesenheit_erklaert']++;
                    $this->beispiel('abwesenheit_erklaert', ['objekt' => $objekt, 'legacy_id' => $legacy, 'feld' => $spalte, 'fa' => $fa->{$spalte} ?? null, 'necta' => $nWert]);

                    continue;
                }
                if ($strenge === -1) {
                    $this->lockerung($objekt, $fa->{$spalte} ?? null, $nWert);
                }
                if ($strenge === 1) {
                    // Necta strenger: automatisch übernehmen, auch über Handpflege hinweg (Sicherheit vor Pflegestand)
                    $uebernehmen[$spalte] = $nWert;
                    $this->zaehler[$objekt === 'item_allergen' ? 'allergen_verschaerft' : 'deklaration_verschaerft']++;
                    $this->beispiel('verschaerft', ['objekt' => $objekt, 'legacy_id' => $legacy, 'feld' => $spalte, 'fa' => $fa->{$spalte} ?? null, 'necta' => $nWert]);
                } elseif ($strenge === 0) {
                    $uebernehmen[$spalte] = $nWert;   // gleich streng (k.A. 0 ⇄ NULL): angleichen, kein Prüffall
                } else {
                    // lockerer (oder Unterarten-JSON): nie still → Prüffall, FA bleibt
                    $this->pruefall($objekt === 'item_allergen' ? 'allergen_aenderung' : 'deklaration_aenderung', $objekt, $legacy, $spalte, $fa->{$spalte} ?? null, $nWert);
                }

                continue;
            }
            if ($handOhneStand) {
                $this->pruefall('manuell_gepflegt', $objekt, $legacy, $spalte, $fa->{$spalte} ?? null, $nWert);

                continue;
            }
            if ($stand === null) {
                $uebernehmen[$spalte] = $nWert;   // erster Abgleich: Necta-Änderungen seit dem Import, keine Handpflege

                continue;
            }
            $s = self::norm($stand[$spalte] ?? null);
            if ($f === $s) {
                $uebernehmen[$spalte] = $nWert;   // FA unverändert seit dem letzten Necta-Stand → Necta-Änderung übernehmen
            } elseif ($n !== $s) {
                $this->pruefall('manuell_gepflegt', $objekt, $legacy, $spalte, $fa->{$spalte} ?? null, $nWert);   // beide geändert
            }
            // n === s: Necta unverändert, FA von Hand anders → nichts zu tun
        }
        if ($uebernehmen !== []) {
            $this->zaehler['objekte_aktualisiert']++;
            $this->zaehler['felder_uebernommen'] += count($uebernehmen);
            $this->beispiel('uebernommen', ['objekt' => $objekt, 'legacy_id' => $legacy] + $uebernehmen);
            $this->kennzeichnungMerken($objekt, (int) ($fa->supplier_item_id ?? 0));   // auch in der Probe (zählt die Rezepte)
        }
        if ($this->probe) {
            return;
        }
        if ($uebernehmen !== []) {
            if ($objekt === 'supplier_item' && array_key_exists('ingredients_supplier', $uebernehmen)) {
                // Zutatenliste geändert → Jod neu ableiten (Query Builder umgeht den Modell-Hook)
                $uebernehmen['iodized_abgeleitet'] = \Platform\FoodAlchemist\Support\JodAusZutaten::wert($uebernehmen['ingredients_supplier']);
            }
            if ($objekt === 'supplier_item' && array_intersect(['designation', 'qty', 'qty_ordering_per_packaging', 'unit_code'], array_keys($uebernehmen)) !== []) {
                // Bezeichnung/Menge/Stückzahl/Einheit geändert → Mengen-Korrektur neu ableiten (mit den übrigen FA-Werten)
                $w = fn (string $f) => array_key_exists($f, $uebernehmen) ? $uebernehmen[$f] : ($fa->{$f} ?? null);
                $uebernehmen += \Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem::mengenKorrektur($w('designation'), $w('qty'),
                    $w('qty_ordering_per_packaging'), $w('unit_code'));
            }
            DB::table($tabelle)->where('id', $fa->id)->update($uebernehmen + ['updated_at' => now()]);
        }
        $this->stand($objekt, $legacy, $necta + ['legacy_id' => $legacy]);
    }

    /**
     * Allergen-Unterarten je Schlüssel nach Strenge: strenger → übernehmen, gleich → bleibt, lockerer → Prüffall
     * `details.<unterart>` (FA-Wert bleibt). Rückgabe: neues details-JSON (sortiert) bzw. NULL, wenn leer.
     */
    private function unterartenAbgleichen(int $legacy, mixed $faJson, mixed $nectaJson, mixed $standJson = null, bool $mitStand = false, bool $vonHand = false): ?string
    {
        $fa = is_string($faJson) ? (array) json_decode($faJson, true) : (array) ($faJson ?? []);
        $necta = is_string($nectaJson) ? (array) json_decode($nectaJson, true) : (array) ($nectaJson ?? []);
        $standD = is_string($standJson) ? (array) json_decode($standJson, true) : (array) ($standJson ?? []);
        $ergebnis = $fa;
        foreach (array_unique([...array_keys($fa), ...array_keys($necta)]) as $unterart) {
            $f = $fa[$unterart] ?? null;
            $n = $necta[$unterart] ?? null;
            $vergleich = self::strenge('item_allergen', $n) <=> self::strenge('item_allergen', $f);
            if ($vergleich === 1) {
                $ergebnis[$unterart] = $n;   // NULL = unbekannt (strenger als nicht_enthalten) → Schlüssel fällt unten weg
                $this->zaehler['unterart_verschaerft']++;
                $this->beispiel('verschaerft', ['objekt' => 'item_allergen', 'legacy_id' => $legacy, 'feld' => "details.{$unterart}", 'fa' => $f, 'necta' => $n]);
            } elseif ($vergleich === -1 && ! $vonHand && (! $mitStand || ($standD[$unterart] ?? null) === $f)
                && self::abwesenheitErklaert('item_allergen', $f, $n)) {
                $ergebnis[$unterart] = $n;   // Abwesenheit erklärt (unbekannt → nicht_enthalten), nicht von Hand gepflegt
                $this->zaehler['allergen_abwesenheit_erklaert']++;
                $this->beispiel('abwesenheit_erklaert', ['objekt' => 'item_allergen', 'legacy_id' => $legacy, 'feld' => "details.{$unterart}", 'fa' => $f, 'necta' => $n]);
            } elseif ($vergleich === -1) {
                $this->lockerung('item_allergen', $f, $n);
                $this->pruefall('allergen_aenderung', 'item_allergen', $legacy, "details.{$unterart}", $f, $n);
            }
        }
        $ergebnis = array_filter($ergebnis, fn ($v) => $v !== null);
        ksort($ergebnis);

        return $ergebnis === [] ? null : json_encode($ergebnis);
    }

    /**
     * Ausdrückliche Erklärung der Abwesenheit (Entscheid Dominique 15.10.2026): nur k.A. → „nicht enthalten“ bzw. „nein“.
     * Allergen: NULL → nicht_enthalten. Deklaration: 0/NULL → 1. Alles, was von enthalten/spuren bzw. 3 weggeht, ist es nicht.
     */
    private static function abwesenheitErklaert(string $objekt, mixed $fa, mixed $necta): bool
    {
        if ($objekt === 'item_allergen') {
            return ($fa === null || $fa === '') && $necta === 'nicht_enthalten';
        }

        return ($fa === null || $fa === '' || (int) $fa === 0) && $necta !== null && $necta !== '' && (int) $necta === 1;
    }

    /** Diagnose: Lockerung je Wechsel zählen, z. B. „allergen: unbekannt→nicht_enthalten“. */
    private function lockerung(string $objekt, mixed $fa, mixed $necta): void
    {
        $name = fn ($v) => $v === null || $v === '' ? 'unbekannt' : (string) $v;
        $schluessel = ($objekt === 'item_allergen' ? 'allergen' : 'deklaration').": {$name($fa)}→{$name($necta)}";
        $this->zaehler['lockerung_je_wechsel'][$schluessel] = ($this->zaehler['lockerung_je_wechsel'][$schluessel] ?? 0) + 1;
    }

    /**
     * Strenge eines Kennzeichnungs-Werts (höher = strenger). Allergene {@see self::ALLERGEN_STRENGE}; Deklaration roh:
     * 3 (ja) > 0/NULL (k.A.) > 1 (nein).
     */
    private static function strenge(string $objekt, mixed $wert): int
    {
        if ($objekt === 'item_allergen') {
            return self::ALLERGEN_STRENGE[(string) ($wert ?? '')] ?? 1;
        }

        return match ($wert === null || $wert === '' ? null : (int) $wert) {
            3 => 2,
            1 => 0,
            default => 1,
        };
    }

    /** Prüffall anlegen oder ein offenes für dasselbe (Objekt, Necta-ID, Feld, Art) aktualisieren. Probe: nur zählen. */
    /**
     * Abweichung an einem Pass-Feld der Kerngruppen. Hat jemand „Pass behalten“ gewählt (Prüffall verworfen) und Necta liefert
     * weiterhin genau diesen Wert gegen genau diesen Pass-Wert, kommt sie nicht wieder; ein neuer Necta-Wert schon.
     */
    private function passAbweichung(string $objekt, int $legacy, int $itemId, string $feld, mixed $faWert, mixed $nectaWert): void
    {
        $behalten = DB::table('foodalchemist_necta_pruefaelle')->where('art', ProduktpassAbweichungService::ART)->where('objekt', $objekt)
            ->where('legacy_id', $legacy)->where('feld', $feld)->where('status', 'verworfen')
            ->get(['fa_wert', 'necta_wert'])
            ->contains(fn ($p) => ProduktpassAbweichungService::gleich($feld, $p->necta_wert, $nectaWert) && ProduktpassAbweichungService::gleich($feld, $p->fa_wert, $faWert));
        if ($behalten) {
            $this->zaehler['produktpass_behalten']++;

            return;
        }
        $this->zaehler['produktpass_abweichung_neu']++;
        $this->pruefall(ProduktpassAbweichungService::ART, $objekt, $legacy, $feld, $faWert, $nectaWert);
        $this->passSignalArtikel[$itemId] = true;
    }

    /** EIN Signal je Artikel mit offener Pass-Abweichung (Schwere = höchste offene), erst am Laufende. */
    private function passSignaleSchreiben(): void
    {
        foreach (array_keys($this->passSignalArtikel) as $itemId) {
            app(ProduktpassAbweichungService::class)->signalAktualisieren((int) $itemId);
        }
        $this->passSignalArtikel = [];
    }

    private function pruefall(string $art, string $objekt, int $legacy, ?string $feld, mixed $faWert, mixed $nectaWert): void
    {
        $this->zaehler['pruefaelle'][$art] = ($this->zaehler['pruefaelle'][$art] ?? 0) + 1;
        $feldName = $feld ?? '—';
        $this->zaehler['pruefaelle_je_feld'][$art][$feldName] = ($this->zaehler['pruefaelle_je_feld'][$art][$feldName] ?? 0) + 1;
        $this->beispiel("pruefall_{$art}", ['objekt' => $objekt, 'legacy_id' => $legacy, 'feld' => $feld, 'fa' => $faWert, 'necta' => $nectaWert]);
        if ($this->probe) {
            return;
        }
        $schluessel = ['art' => $art, 'objekt' => $objekt, 'legacy_id' => $legacy, 'feld' => $feld, 'status' => 'offen'];
        $werte = ['fa_wert' => self::text($faWert), 'necta_wert' => self::text($nectaWert), 'lauf_id' => $this->laufId, 'updated_at' => now()];
        $offen = DB::table('foodalchemist_necta_pruefaelle')->where($schluessel)->value('id');
        $offen !== null
            ? DB::table('foodalchemist_necta_pruefaelle')->where('id', $offen)->update($werte)
            : DB::table('foodalchemist_necta_pruefaelle')->insert(['uuid' => (string) UuidV7::generate()] + $schluessel + $werte + ['created_at' => now()]);
    }

    private function signalFehler(int $verbindungId, string $fehler): void
    {
        $master = $this->masterTeam();
        if ($master === null) {
            return;
        }
        app(SignalService::class)->erzeuge($master, SignalTyp::NectaAbgleichFehler, SignalSeverity::Warnung,
            'Necta-Abgleich fehlgeschlagen', [
                'dedup_key' => "necta_abgleich_fehler:{$verbindungId}", 'source' => 'necta_abgleich', 'ref_type' => 'necta_lauf', 'ref_id' => $this->laufId,
                'description' => mb_substr('Lauf '.$this->laufId.': '.$fehler, 0, 1000),
                'payload' => ['lauf_id' => $this->laufId, 'verbindung_id' => $verbindungId, 'fehler' => mb_substr($fehler, 0, 500)],
            ]);
    }

    /** Vergleichbare Form: NULL/'' gleich, Zahlen numerisch (DB-Dezimal „1.0000“ = 1), Wahrheitswerte als 0/1, JSON sortiert. */
    public static function norm(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v) && ! preg_match('/^0\d|^\s|\s$/', $v))) {
            return rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.');
        }
        if (is_string($v) && ($v[0] === '{' || $v[0] === '[')) {
            $d = json_decode($v, true);
            if (is_array($d)) {
                ksort($d);

                return json_encode($d);
            }
        }

        return (string) $v;
    }

    private static function text(mixed $v): ?string
    {
        return $v === null ? null : (is_scalar($v) ? (string) $v : json_encode($v));
    }
}
