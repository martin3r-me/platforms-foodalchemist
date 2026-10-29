<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistItemNutritional;
use Platform\FoodAlchemist\Services\Naehrwerte\NaehrwertBefund;
use Platform\FoodAlchemist\Services\Naehrwerte\NaehrwertPruefService;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * Nährwerte eines GP aus seinen Lieferantenartikeln — EINE Regel für Erdung (SensorikService) und Rezept/Panel
 * (GpAggregateService). Dominique 29.10.2026: „Eine Null ist oft echt (Salz: Fett 0, Zucker 0, kcal 0; Öl: Kohlenhydrate 0).
 * Nur ALLE Kernwerte 0 heißt nicht deklariert.“
 *
 *  1. Eine Artikelzeile zählt nur, wenn mindestens ein Kernwert > 0 ist (Leer-Label-Guard: der Necta-Import lieferte
 *     „keine Angabe“ als lauter Nullen). Bis 10/2026 hatte nur die Erdung diesen Guard; die Rezept-Nährwerte zählten
 *     Null-Zeilen als Messung (lokal 208 GPs mit kcal 0 statt „unbekannt“).
 *  2. In einer zählenden Zeile ist 0 ein Messwert, nur NULL heißt „fehlt“. Bis 10/2026 warf die Erdung jede 0 weg
 *     (`AVG(NULLIF(x,0))`): Pflaume Fett 0 oder Schweineschulter Zucker 0 galten als nicht deklariert.
 *  3. Kennwert je Nährstoff ist der Median über die Artikel, nicht der Mittelwert: ein falsch gepflegter Artikel
 *     (Einheit, Tippfehler, falsche Ware) verschiebt den Wert bei drei Artikeln nicht mehr. Bei zwei Artikeln ist
 *     der Median der Mittelwert.
 *
 * Nur aktive Artikel (nicht ausgelistet, nicht gelöscht, Verknüpfung nicht gelöscht). Mit `$sicht` nur die Artikel, die dieses Team sieht
 * (Lead-Sicht 29.10.2026: ein Mandant rechnet nie mit Nährwerten von Ware, die er nicht beziehen kann); ohne Sicht alle.
 */
class NaehrwertAggregatService
{
    /** Kernwerte des Leer-Label-Guards: ist keiner davon > 0, ist die Zeile „nicht deklariert“. */
    public const GUARD_FELDER = ['energy_kcal', 'sodium', 'fat', 'sugar', 'protein', 'carbs_absorbable'];

    /**
     * Kennwert (Median) und Anzahl Artikel mit Wert je GP und Nährstoff.
     *
     * @param  list<int>  $gpIds
     * @param  list<string>  $felder  Spalten aus foodalchemist_item_nutritionals
     * @return array<int, array<string, array{wert: ?float, n: int}>> [gp_id][feld]; GPs ohne zählende Zeile fehlen
     */
    public function kennwerte(array $gpIds, array $felder, ?Team $sicht = null): array
    {
        $out = [];
        foreach ($this->zeilen($gpIds, $felder, $sicht) as $gpId => $zeilen) {
            foreach ($felder as $f) {
                $werte = array_values(array_filter(array_column($zeilen, $f), fn ($v) => $v !== null));
                $out[$gpId][$f] = ['wert' => self::median($werte), 'n' => count($werte)];
            }
        }

        return $out;
    }

    /**
     * Zählende Artikelzeilen je GP (Guard angewandt), Werte als float|null. EINE Query je 1.000 GPs.
     * Im Modus `scharf` der Nährwert-Prüfung fallen Artikel mit hartem Befund heraus (außer freigegeben), eine eindeutige
     * Einheiten-Probe liefert die umgerechneten Werte ({@see NaehrwertPruefService}).
     *
     * @param  list<int>  $gpIds
     * @param  list<string>  $felder
     * @return array<int, list<array<string, ?float>>>
     */
    public function zeilen(array $gpIds, array $felder, ?Team $sicht = null): array
    {
        $scharf = \Platform\FoodAlchemist\Support\NaehrwertPruefModus::istScharf();
        $out = [];
        foreach ($this->pruefung($gpIds, $scharf, $sicht) as $gpId => $artikel) {
            foreach ($artikel as $a) {
                if ($scharf && ! $a['zaehlt']) {
                    continue;
                }
                $werte = $scharf ? $a['wirksam'] : $a['roh'];
                $out[$gpId][] = array_map(fn ($f) => $werte[$f] ?? null, array_combine($felder, $felder));
            }
        }

        return $out;
    }

    /**
     * Prüfung je Artikel der GPs (Guard angewandt): Rohwerte, wirksame Werte, Befunde, ob er zählt (modus scharf).
     * Grundlage für die Aggregation und den Trockenlauf.
     *
     * @param  list<int>  $gpIds
     * @param  Team|null  $sicht  nur die Artikel, die dieses Team sieht (Mandanten-Sicht); null = alle
     * @return array<int, list<array{la_id: int, lieferant: ?string, bezeichnung: ?string, roh: array<string, ?float>, wirksam: array<string, ?float>,
     *     faktor: ?int, befunde: list<NaehrwertBefund>, freigegeben: bool, zaehlt: bool}>>
     */
    public function pruefung(array $gpIds, bool $mitBefunden = true, ?Team $sicht = null): array
    {
        $gpIds = array_values(array_unique(array_filter(array_map('intval', $gpIds))));
        $spalten = array_values(array_unique(array_merge(FoodAlchemistItemNutritional::KERNWERTE, NaehrwertPruefService::FELDER, self::GUARD_FELDER)));
        $pruefer = app(NaehrwertPruefService::class);
        $out = [];
        foreach (array_chunk($gpIds, 1000) as $chunk) {
            $rows = DB::table('foodalchemist_item_nutritionals AS n')
                ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'n.supplier_item_id')
                ->join('foodalchemist_supplier_items AS i', 'i.id', '=', 'n.supplier_item_id')
                ->leftJoin('foodalchemist_gps AS g', 'g.id', '=', 's.gp_id')
                ->leftJoin('foodalchemist_suppliers AS l', 'l.id', '=', 'i.supplier_id')
                ->whereIn('s.gp_id', $chunk)
                ->where('i.is_discontinued', false)
                ->whereNull('n.deleted_at')->whereNull('s.deleted_at')->whereNull('i.deleted_at')
                ->when($sicht, fn ($q) => $q->whereIn('n.supplier_item_id', FoodAlchemistSupplierItem::visibleToTeam($sicht)->select('foodalchemist_supplier_items.id')))
                ->where(function ($q) {                          // Leer-Label-Guard
                    foreach (self::GUARD_FELDER as $f) {
                        $q->orWhere("n.{$f}", '>', 0);
                    }
                })
                ->orderBy('n.supplier_item_id')
                ->select(array_merge(['s.gp_id', 'i.id AS la_id', 'i.designation', 'l.name AS lieferant', 'g.name AS gp_name',
                    'g.commodity_group_code AS wg', 'g.sub_category AS sub'], array_map(fn ($f) => "n.{$f}", $spalten)))
                ->get();
            $gruppen = [];
            foreach ($rows as $r) {
                $gruppen[(int) $r->gp_id][] = $r;
            }
            // Tabelle fehlt (Migration noch nicht gelaufen) = keine Freigaben — der Trockenlauf darf daran nicht scheitern.
            $freigaben = $mitBefunden && \Illuminate\Support\Facades\Schema::hasTable('foodalchemist_item_nutrition_freigaben') ? DB::table('foodalchemist_item_nutrition_freigaben')
                ->whereIn('supplier_item_id', $rows->pluck('la_id')->all())->pluck('werte_hash', 'supplier_item_id')->all() : [];
            foreach ($gruppen as $gpId => $gRows) {
                $roh = array_map(function ($r) use ($spalten) {
                    $z = [];
                    foreach ($spalten as $f) {
                        $z[$f] = $r->{$f} !== null ? (float) $r->{$f} : null;
                    }

                    return $z;
                }, $gRows);
                $befunde = $mitBefunden ? $pruefer->pruefeGruppe($roh, ['wg' => $gRows[0]->wg, 'sub' => $gRows[0]->sub, 'name' => $gRows[0]->gp_name]) : [];
                foreach ($gRows as $i => $r) {
                    $b = $befunde[$i] ?? [];
                    $wirk = $mitBefunden ? $pruefer->wirksameWerte($roh[$i]) : ['werte' => [], 'faktor' => null];
                    $frei = isset($freigaben[$r->la_id]) && hash_equals($freigaben[$r->la_id], NaehrwertPruefService::werteHash($roh[$i]));
                    $out[$gpId][] = [
                        'la_id' => (int) $r->la_id, 'lieferant' => $r->lieferant, 'bezeichnung' => $r->designation,
                        'roh' => $roh[$i], 'wirksam' => array_merge($roh[$i], $wirk['werte']), 'faktor' => $wirk['faktor'],
                        'befunde' => $b, 'freigegeben' => $frei, 'zaehlt' => $frei || ! NaehrwertPruefService::istHart($b),
                    ];
                }
            }
        }

        return $out;
    }

    /** @param  list<float>  $werte */
    public static function median(array $werte): ?float
    {
        if ($werte === []) {
            return null;
        }
        sort($werte);
        $mitte = intdiv(count($werte), 2);

        return count($werte) % 2 === 1 ? (float) $werte[$mitte] : ($werte[$mitte - 1] + $werte[$mitte]) / 2;
    }
}
