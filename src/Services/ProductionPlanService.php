<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistKitchenRole;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;

/**
 * Stufe 3 P3.3 — Auto-Produktionsplaner (Verteiler).
 *
 * Schlägt vor, an welchem Tag an welchem Posten produziert wird — kapazitäts- und kostenbewusst.
 * Rein: `schlage()` SCHREIBT NICHTS, es liefert einen Vorschlag zum Review. Erst `uebernehmen()`
 * setzt ihn per `ProductionOrderService::assignLine()` (überlebt Recompute, `syncPlanDates`).
 *
 * Zwei Hebel je Zeile: `station_id` (aus `recipe.default_station_id` geroutet) und `vorlauf_tage`
 * (vorproduzierbare Zeilen werden auf frühere, freie Tage gezogen). Frische Zeilen
 * (`max_vorlauf_tage` 0/NULL) bleiben am Liefertag.
 */
class ProductionPlanService
{
    private const AKTIVE_STATUS = ['planned', 'in_progress'];

    public function __construct(
        private ProductionOrderService $orders,
        private TeamSettingsService $settings,
    ) {}

    /**
     * @return array{
     *   vorschlag: list<array<string,mixed>>,
     *   last: array<string, array<string, array{geplant:int,kapazitaet:?int,stufe:string}>>,
     *   kosten: array{gesamt_eur: float, je_tag: array<string,float>},
     *   nicht_zugeteilt: list<array<string,mixed>>,
     *   aenderungen: int
     * }
     */
    public function schlage(Team $team, string $von, string $bis): array
    {
        $zeilen = $this->zeilenImFenster($team, $von, $bis);
        $stationen = FoodAlchemistProductionStation::where('team_id', $team->id)
            ->whereNull('deleted_at')->get()->keyBy('id');
        $rateProMin = $this->rateProMin($team);

        // 1. Routen + natürlichen Tag (Vorlauf 0) setzen; Last je Tag×Posten aufbauen.
        $plan = [];          // line_id => [station_id, vorlauf, tag(plan_date), arbeitszeit, max_vorlauf, ...]
        $last = [];          // "tag|station" => geplant_min
        foreach ($zeilen as $z) {
            $station = $z->default_station_id ?: $z->station_id;   // Routing: Rezept-Default > bestehende Zuteilung
            $tag = Carbon::parse($z->production_date)->toDateString();
            $arbeit = (int) ($z->arbeitszeit_min ?? 0);
            $plan[$z->id] = [
                'line_id' => (int) $z->id,
                'station_id' => $station ? (int) $station : null,
                'vorlauf_tage' => 0,
                'tag' => $tag,
                'liefertag' => $tag,
                'arbeitszeit_min' => $arbeit,
                'max_vorlauf' => $z->max_vorlauf_tage !== null ? (int) $z->max_vorlauf_tage : 0,
                'rezept' => $z->rezept,
                'auftrag' => $z->auftrag,
                'alt_station' => $z->station_id ? (int) $z->station_id : null,
                'alt_vorlauf' => (int) ($z->vorlauf_tage ?? 0),
            ];
            if ($station !== null) {
                $last[$tag . '|' . $station] = ($last[$tag . '|' . $station] ?? 0) + $arbeit;
            }
        }

        // 2. Glätten: überlastete (Tag,Posten) entlasten, indem vorproduzierbare Zeilen nach vorn ziehen.
        $this->glaette($plan, $last, $stationen);

        // 3. Ergebnis aufbereiten.
        return $this->ergebnis($plan, $stationen, $rateProMin);
    }

    /** Übernahme nach Review: setzt Posten + Vorlauf je Zeile. Optional auf eine Teilmenge (line_ids). */
    public function uebernehmen(Team $team, array $vorschlag, ?array $nurLinien = null): int
    {
        $n = 0;
        foreach ($vorschlag as $v) {
            if ($nurLinien !== null && ! in_array($v['line_id'], $nurLinien, true)) {
                continue;
            }
            if ($v['station_id'] === null) {
                continue;   // ohne Ziel-Posten nichts zu setzen (bleibt Review-Fall)
            }
            $this->orders->assignLine($team, (int) $v['line_id'], [
                'station_id' => $v['station_id'],
                'vorlauf_tage' => (int) $v['vorlauf_tage'],
            ]);
            $n++;
        }

        return $n;
    }

    /** Zeilen aus aktiven Aufträgen mit Liefertag im Fenster + Rezept-Planer-Attributen. */
    private function zeilenImFenster(Team $team, string $von, string $bis)
    {
        return DB::table('foodalchemist_production_order_lines as l')
            ->join('foodalchemist_production_orders as o', 'o.id', '=', 'l.production_order_id')
            ->leftJoin('foodalchemist_recipes as r', 'r.id', '=', 'l.recipe_id')
            ->whereNull('l.deleted_at')->whereNull('o.deleted_at')
            ->where('o.team_id', $team->id)
            ->whereIn('o.status', self::AKTIVE_STATUS)
            ->where('l.is_struck', false)
            ->whereDate('o.production_date', '>=', $von)->whereDate('o.production_date', '<=', $bis)
            ->orderBy('o.production_date')->orderBy('l.position')
            ->select([
                'l.id', 'l.station_id', 'l.arbeitszeit_min', 'l.vorlauf_tage',
                'o.production_date', 'o.name as auftrag',
                'r.name as rezept', 'r.default_station_id', 'r.max_vorlauf_tage',
            ])->get();
    }

    /** Greedy-Glättung: pro (Tag,Posten) über Kapazität → vorziehbare Zeilen auf frühere freie Tage. */
    private function glaette(array &$plan, array &$last, $stationen): void
    {
        // Kandidaten je (Tag,Posten), absteigend nach Haltbarkeit (längste zuerst vorziehen).
        $tage = collect($plan)->pluck('tag')->unique()->sort()->values();

        foreach ($stationen as $sid => $station) {
            $kap = fn (string $tag) => $station->kapazitaetAm(Carbon::parse($tag));

            foreach ($tage as $D) {
                $capD = $kap($D);
                if ($capD === null) {
                    continue;   // Posten ohne Kapazität warnt/plant nie
                }

                $sicherung = 0;
                while (($last[$D . '|' . $sid] ?? 0) > $capD && $sicherung++ < 500) {
                    $kandidat = $this->pullKandidat($plan, $sid, $D);
                    if ($kandidat === null) {
                        break;   // nichts mehr vorziehbar an diesem Tag/Posten
                    }

                    $arbeit = $plan[$kandidat]['arbeitszeit_min'];
                    $verschoben = false;
                    for ($k = 1; $k <= $plan[$kandidat]['max_vorlauf']; $k++) {
                        $d = Carbon::parse($D)->subDays($k)->toDateString();
                        $capd = $kap($d);
                        if ($capd === null) {
                            continue;
                        }
                        if ($capd - ($last[$d . '|' . $sid] ?? 0) >= $arbeit) {
                            $last[$D . '|' . $sid] -= $arbeit;
                            $last[$d . '|' . $sid] = ($last[$d . '|' . $sid] ?? 0) + $arbeit;
                            $plan[$kandidat]['tag'] = $d;
                            $plan[$kandidat]['vorlauf_tage'] = $k;
                            $verschoben = true;
                            break;
                        }
                    }
                    if (! $verschoben) {
                        // Diese Zeile lässt sich nicht entlasten → als „bearbeitet" markieren, sonst Endlosschleife.
                        $plan[$kandidat]['_fix'] = true;
                    }
                }
            }
        }
    }

    /** Vorziehbare Zeile an (Tag,Posten): max_vorlauf>0, noch am Tag, längste Haltbarkeit zuerst. */
    private function pullKandidat(array $plan, int $sid, string $D): ?int
    {
        $best = null;
        $bestVorlauf = 0;
        foreach ($plan as $id => $p) {
            if ($p['station_id'] === $sid && $p['tag'] === $D && $p['vorlauf_tage'] === 0
                && $p['max_vorlauf'] > 0 && ($p['_fix'] ?? false) === false && $p['arbeitszeit_min'] > 0) {
                if ($p['max_vorlauf'] > $bestVorlauf) {
                    $best = $id;
                    $bestVorlauf = $p['max_vorlauf'];
                }
            }
        }

        return $best;
    }

    private function ergebnis(array $plan, $stationen, array $rateProMin): array
    {
        $vorschlag = [];
        $nichtZugeteilt = [];
        $last = [];
        $kostenJeTag = [];
        $gesamt = 0.0;
        $aenderungen = 0;

        foreach ($plan as $p) {
            $station = $p['station_id'] !== null ? $stationen->get($p['station_id']) : null;
            $eintrag = [
                'line_id' => $p['line_id'],
                'station_id' => $p['station_id'],
                'station' => $station?->name,
                'vorlauf_tage' => $p['vorlauf_tage'],
                'plan_date' => $p['tag'],
                'liefertag' => $p['liefertag'],
                'rezept' => $p['rezept'],
                'auftrag' => $p['auftrag'],
                'arbeitszeit_min' => $p['arbeitszeit_min'],
            ];

            if ($p['station_id'] === null) {
                $nichtZugeteilt[] = $eintrag;
            } else {
                $vorschlag[] = $eintrag;
                $last[$p['tag']][$p['station_id']]['geplant'] = ($last[$p['tag']][$p['station_id']]['geplant'] ?? 0) + $p['arbeitszeit_min'];

                $kosten = $p['arbeitszeit_min'] * ($rateProMin[$p['station_id']] ?? $rateProMin['_flat']);
                $kostenJeTag[$p['tag']] = ($kostenJeTag[$p['tag']] ?? 0.0) + $kosten;
                $gesamt += $kosten;
            }

            if ($p['station_id'] !== $p['alt_station'] || $p['vorlauf_tage'] !== $p['alt_vorlauf']) {
                $aenderungen++;
            }
        }

        // Last mit Kapazität + Ampelstufe anreichern.
        $lastOut = [];
        foreach ($last as $tag => $proStation) {
            foreach ($proStation as $sid => $werte) {
                $station = $stationen->get($sid);
                $cap = $station?->kapazitaetAm(Carbon::parse($tag));
                $lastOut[$tag][$sid] = [
                    'geplant' => $werte['geplant'],
                    'kapazitaet' => $cap,
                    'stufe' => $this->stufe($werte['geplant'], $cap),
                ];
            }
        }

        return [
            'vorschlag' => $vorschlag,
            'last' => $lastOut,
            'kosten' => ['gesamt_eur' => round($gesamt, 2), 'je_tag' => array_map(fn ($v) => round($v, 2), $kostenJeTag)],
            'nicht_zugeteilt' => $nichtZugeteilt,
            'aenderungen' => $aenderungen,
        ];
    }

    private function stufe(int $geplant, ?int $kapazitaet): string
    {
        if ($kapazitaet === null || $kapazitaet <= 0) {
            return 'ohne_kapazitaet';
        }
        $pct = $geplant / $kapazitaet * 100;

        return $pct > 100 ? 'ueberlast' : ($pct >= 85 ? 'eng' : 'ok');
    }

    /**
     * €/Personen-Minute je Posten: aus der Rollen-Besetzung (Σ Anzahl × Rollensatz / Σ Köpfe),
     * Fallback flacher Team-Stundensatz. Key '_flat' trägt den Fallback für Posten ohne Besetzung.
     */
    private function rateProMin(Team $team): array
    {
        $flatMin = $this->settings->stundensatz($team) / 60;
        $rollenSatzMin = FoodAlchemistKitchenRole::where('team_id', $team->id)->whereNull('deleted_at')
            ->pluck('stundensatz_eur', 'id')
            ->map(fn ($s) => $s !== null ? (float) $s / 60 : $flatMin);

        $out = ['_flat' => $flatMin];
        foreach (FoodAlchemistProductionStation::where('team_id', $team->id)->whereNull('deleted_at')->get() as $station) {
            $besetzung = $station->besetzung ?? [];
            $koepfe = array_sum(array_map('intval', $besetzung));
            if ($koepfe <= 0) {
                $out[$station->id] = $flatMin;

                continue;
            }
            $summe = 0.0;
            foreach ($besetzung as $roleId => $anzahl) {
                $summe += (int) $anzahl * ($rollenSatzMin[(int) $roleId] ?? $flatMin);
            }
            $out[$station->id] = $summe / $koepfe;   // Durchschnitt je Personen-Minute
        }

        return $out;
    }
}
