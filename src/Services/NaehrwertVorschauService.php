<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemNutritional;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Vorschau zur Umstellung der GP-Nährwerte auf {@see NaehrwertAggregatService} (Nullen echt, Leer-Guard überall, Median):
 * je Achse vorher/nachher, Zahl betroffener GPs und Rezepte, größte Sprünge. Nur lesend, über MCP aufrufbar
 * (`foodalchemist.naehrwerte_vorschau.GET`), damit der Lauf ohne Server-Zugang geht.
 *
 * „Nachher“ ruft die echten Dienste (SensorikService::erdungBulk, NaehrwertAggregatService) — die Vorschau zeigt also,
 * was die Pipeline rechnet. „Vorher“ bildet die Formeln bis 10/2026 nach: Erdung `AVG(NULLIF(x,0))` mit Leer-Guard,
 * Rezept/Panel `AVG(x)` ohne Leer-Guard. Kann nach der Umstellung auf io entfallen.
 */
class NaehrwertVorschauService
{
    /** Erdung: Achse → Gehalt im erdungBulk-Ergebnis. */
    private const ERDUNG = ['salzig' => 'salt_g', 'suess' => 'sugar', 'fettig' => 'fat'];

    /** Rezept-Nährwerte (GpAggregateService::naehrwerte) je Achse. */
    private const REZEPT = ['energy_kcal' => 'kcal', 'fat' => 'fett', 'saturated_fat' => 'ges_fett', 'carbs_absorbable' => 'kh',
        'sugar' => 'zucker', 'protein' => 'eiweiss', 'sodium' => 'salz'];

    /**
     * @param  float  $schwelle  relative Änderung, ab der ein Wert als geändert zählt (0,2 = 20 %); neu gemessen/entfällt zählt immer
     * @param  list<int>|null  $gpIds  nur diese GPs (sonst alle, die das Team sieht und die Nährwertzeilen haben)
     * @return array<string, mixed>
     */
    public function bericht(Team $team, float $schwelle = 0.2, int $limit = 10, ?array $gpIds = null): array
    {
        $sichtbar = FoodAlchemistGp::visibleToTeam($team)->when($gpIds !== null, fn ($q) => $q->whereIn('id', $gpIds));
        $roh = $this->rohzeilen($sichtbar->pluck('id')->all());
        $ids = array_keys($roh);
        $namen = FoodAlchemistGp::visibleToTeam($team)->whereIn('id', $ids)->pluck('name', 'id')->all();

        // Erdung
        $erdNeu = app(SensorikService::class)->erdungBulk($ids);
        $erdung = [];
        foreach (self::ERDUNG as $dim => $g) {
            $paare = [];
            foreach ($ids as $id) {
                $paare[$id] = [$this->erdungAlt($roh[$id])[$g] ?? null, $erdNeu[$id][$dim]['gehalt'] ?? null];
            }
            $erdung[$dim] = $this->vergleich($paare, $namen, $schwelle, $limit, 'g/100 g');
        }

        // Rezept-Nährwerte
        $neu = app(NaehrwertAggregatService::class)->kennwerte($ids, FoodAlchemistItemNutritional::KERNWERTE);
        $rezept = [];
        $geaenderteGps = [];
        foreach (self::REZEPT as $feld => $label) {
            $paare = [];
            foreach ($ids as $id) {
                $faktor = $feld === 'sodium' ? 0.0025 : 1.0;        // Salz g = Natrium mg × 0,0025
                $alt = $this->rezeptAlt($roh[$id], $feld);
                $nachher = $neu[$id][$feld]['wert'] ?? null;
                $paare[$id] = [$alt !== null ? $alt * $faktor : null, $nachher !== null ? $nachher * $faktor : null];
            }
            $rezept[$label] = $this->vergleich($paare, $namen, $schwelle, $limit, $feld === 'energy_kcal' ? 'kcal/100 g' : 'g/100 g');
            foreach ($rezept[$label]['_ids'] as $id) {
                $geaenderteGps[$id] = true;
            }
        }
        $kcalNullZuUnbekannt = count(array_filter($ids, fn ($id) => $this->rezeptAlt($roh[$id], 'energy_kcal') === 0.0
            && ($neu[$id]['energy_kcal']['wert'] ?? null) === null));

        $rezepte = $geaenderteGps === [] ? 0 : FoodAlchemistRecipe::visibleToTeam($team)
            ->whereIn('id', DB::table('foodalchemist_recipe_ingredients')->whereIn('gp_id', array_keys($geaenderteGps))
                ->whereNull('deleted_at')->select('recipe_id'))
            ->count();

        $ohneIds = fn (array $a) => array_map(fn ($x) => array_diff_key($x, ['_ids' => 1]), $a);

        return [
            'regel' => 'Nachher: Zeile zählt nur, wenn ein Kernwert > 0 ist; darin ist 0 ein Messwert, NULL fehlt; Kennwert = Median. '
                . 'Vorher: Erdung Mittelwert ohne Nullen, Rezept-Nährwerte Mittelwert inkl. Null-Zeilen.',
            'schwelle_pct' => (int) round($schwelle * 100),
            'gps_mit_naehrwertzeilen' => count($ids),
            'erdung' => $ohneIds($erdung),
            'rezept_naehrwerte' => $ohneIds($rezept),
            'rezept_wirkung' => [
                'gps_mit_geaendertem_rezeptwert' => count($geaenderteGps),
                'rezepte_mit_betroffener_zutat' => $rezepte,
                'gps_kcal_0_wird_unbekannt' => $kcalNullZuUnbekannt,
                'hinweis' => 'Rezepte sind direkt verwendete Zutaten-GPs; Sub-Rezepte ziehen beim Neuberechnen nach. Gespeicherte Rezept-Nährwerte ändern sich erst beim nächsten Recompute.',
            ],
        ];
    }

    /**
     * Alle Nährwertzeilen (ohne Leer-Guard) der aktiven Artikel je GP — Grundlage für „vorher“.
     *
     * @return array<int, list<array<string, ?float>>>
     */
    private function rohzeilen(array $gpIds): array
    {
        $felder = FoodAlchemistItemNutritional::KERNWERTE;
        $out = [];
        foreach (array_chunk($gpIds, 1000) as $chunk) {
            $rows = DB::table('foodalchemist_item_nutritionals AS n')
                ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'n.supplier_item_id')
                ->join('foodalchemist_supplier_items AS i', 'i.id', '=', 'n.supplier_item_id')
                ->whereIn('s.gp_id', $chunk)
                ->where('i.is_discontinued', false)
                ->whereNull('n.deleted_at')->whereNull('s.deleted_at')->whereNull('i.deleted_at')
                ->select(array_merge(['s.gp_id'], array_map(fn ($f) => "n.{$f}", $felder)))
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

    /** Erdung bis 10/2026: Leer-Guard, je Feld Mittelwert ohne Nullen, Zucker-Teilmengen-Guard. */
    private function erdungAlt(array $zeilen): array
    {
        $zeilen = array_filter($zeilen, function ($z) {
            foreach (NaehrwertAggregatService::GUARD_FELDER as $f) {
                if (($z[$f] ?? 0) > 0) {
                    return true;
                }
            }

            return false;
        });
        $avg = function (string $f) use ($zeilen) {
            $w = array_values(array_filter(array_column($zeilen, $f), fn ($v) => $v !== null && $v != 0));

            return $w === [] ? null : array_sum($w) / count($w);
        };
        $g = ['salt_g' => ($na = $avg('sodium')) !== null ? $na * 0.0025 : null, 'sugar' => $avg('sugar'), 'fat' => $avg('fat')];
        $kh = $avg('carbs_absorbable');
        if ($g['sugar'] !== null && $kh !== null && $g['sugar'] > $kh * 1.05 + 0.5) {
            $g['sugar'] = null;
        }

        return $g;
    }

    /** Rezept/Panel bis 10/2026: Mittelwert über alle Zeilen, NULL fällt raus, Null-Zeilen zählen mit. */
    private function rezeptAlt(array $zeilen, string $feld): ?float
    {
        $w = array_values(array_filter(array_column($zeilen, $feld), fn ($v) => $v !== null));

        return $w === [] ? null : array_sum($w) / count($w);
    }

    /**
     * @param  array<int, array{0: ?float, 1: ?float}>  $paare  [gp_id => [vorher, nachher]]
     * @return array<string, mixed>
     */
    private function vergleich(array $paare, array $namen, float $schwelle, int $limit, string $einheit): array
    {
        $z = ['neu_gemessen' => 0, 'entfaellt' => 0, 'sinkt' => 0, 'steigt' => 0];
        $spruenge = [];
        $wechsel = [];
        $ids = [];
        foreach ($paare as $id => [$vor, $nach]) {
            if ($vor === null && $nach === null) {
                continue;
            }
            $zeile = ['gp_id' => $id, 'name' => $namen[$id] ?? null, 'vorher' => $this->r($vor), 'nachher' => $this->r($nach)];
            if ($vor === null || $nach === null) {
                $z[$vor === null ? 'neu_gemessen' : 'entfaellt']++;
                $ids[] = $id;
                $wechsel[] = $zeile;

                continue;
            }
            $basis = max(abs($vor), abs($nach));
            if ($basis == 0 || abs($nach - $vor) / $basis < $schwelle) {
                continue;
            }
            $z[$nach < $vor ? 'sinkt' : 'steigt']++;
            $ids[] = $id;
            $spruenge[] = $zeile + ['_abstand' => abs($nach - $vor)];
        }
        usort($spruenge, fn ($a, $b) => $b['_abstand'] <=> $a['_abstand']);

        return $z + [
            'geaendert' => count($ids),
            'einheit' => $einheit,
            'groesste_spruenge' => array_map(fn ($s) => array_diff_key($s, ['_abstand' => 1]), array_slice($spruenge, 0, $limit)),
            'beispiele_neu_oder_entfaellt' => array_slice($wechsel, 0, $limit),
            '_ids' => $ids,
        ];
    }

    private function r(?float $x): ?float
    {
        return $x === null ? null : round($x, 2);
    }
}
