<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;

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
 * Nur aktive Artikel (nicht ausgelistet, nicht gelöscht, Verknüpfung nicht gelöscht).
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
    public function kennwerte(array $gpIds, array $felder): array
    {
        $out = [];
        foreach ($this->zeilen($gpIds, $felder) as $gpId => $zeilen) {
            foreach ($felder as $f) {
                $werte = array_values(array_filter(array_column($zeilen, $f), fn ($v) => $v !== null));
                $out[$gpId][$f] = ['wert' => self::median($werte), 'n' => count($werte)];
            }
        }

        return $out;
    }

    /**
     * Zählende Artikelzeilen je GP (Guard angewandt), Werte als float|null. EINE Query je 1.000 GPs.
     *
     * @param  list<int>  $gpIds
     * @param  list<string>  $felder
     * @return array<int, list<array<string, ?float>>>
     */
    public function zeilen(array $gpIds, array $felder): array
    {
        $gpIds = array_values(array_unique(array_filter(array_map('intval', $gpIds))));
        $spalten = array_values(array_unique(array_merge($felder, self::GUARD_FELDER)));
        $out = [];
        foreach (array_chunk($gpIds, 1000) as $chunk) {
            $rows = DB::table('foodalchemist_item_nutritionals AS n')
                ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'n.supplier_item_id')
                ->join('foodalchemist_supplier_items AS i', 'i.id', '=', 'n.supplier_item_id')
                ->whereIn('s.gp_id', $chunk)
                ->where('i.is_discontinued', false)
                ->whereNull('n.deleted_at')->whereNull('s.deleted_at')->whereNull('i.deleted_at')
                ->where(function ($q) {                          // Leer-Label-Guard
                    foreach (self::GUARD_FELDER as $f) {
                        $q->orWhere("n.{$f}", '>', 0);
                    }
                })
                ->select(array_merge(['s.gp_id'], array_map(fn ($f) => "n.{$f}", $spalten)))
                ->get();
            foreach ($rows as $r) {
                $zeile = [];
                foreach ($felder as $f) {
                    $zeile[$f] = $r->{$f} !== null ? (float) $r->{$f} : null;
                }
                $out[(int) $r->gp_id][] = $zeile;
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
