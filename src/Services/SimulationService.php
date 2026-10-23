<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;

/**
 * R2.2 — Was-wäre-wenn-Simulation: hypothetisches Preisszenario (Warengruppe ODER
 * Einzelartikel ODER GP, ± X %) → Portfolio-Antwort (Marge-Delta gesamt + Top-20
 * betroffene Gerichte) + Ersatzvorschläge aus dem Äquivalenz-Katalog.
 *
 * REIN LESEND — verändert keine Echtdaten (nutzt MargeImpactService::impactFuerGps,
 * dieselbe Impact-Rechnung wie der R2.1-Preis-Alarm, nur vorwärts/hypothetisch).
 */
class SimulationService
{
    public function __construct(private MargeImpactService $impact)
    {
    }

    /**
     * @param  string  $scope  'warengruppe' | 'artikel' | 'gp' | 'lieferant'
     * @param  string  $ref    WG-Code | supplier_item_id | gp_id | supplier_id
     * @return array{scope:string,ref:string,delta_pct:float,ratio:float,n_gps:int,n_recipes:int,n_gerichte:int,n_concepts:int,marge_delta_eur:float,top:list<array>,substitutions:list<array>}
     */
    public function simuliere(Team $team, string $scope, string $ref, float $deltaPct): array
    {
        $ratio = 1 + $deltaPct / 100;
        $gpIds = $this->gpsFuerScope($team, $scope, $ref);

        $res = $this->impact->impactFuerGps($team, $gpIds, $ratio, teamSicht: true);   // Spec 85 PR-F: Preise des Betrachters, Team-Brille
        $res['scope'] = $scope;
        $res['ref'] = $ref;
        $res['delta_pct'] = $deltaPct;
        $res['ratio'] = round($ratio, 4);
        $res['substitutions'] = $this->substitutionen($gpIds);

        return $res;
    }

    /**
     * GP-ids für den Szenario-Scope. Spec 85 PR-F: aus den GPs, die das Team SIEHT (eigene, Master-Kette, globale/Stamm-Paket-GPs) — vorher nur die Kette
     * ohne globale GPs; ein vorgegebener GP wird auf Sichtbarkeit geprüft. Maßgeblich ist der WIRKSAME Lead des Teams (sein Pin vor dem Plattform-Lead),
     * nur GPs mit wirksamem Lead treiben den EK.
     *
     * @return list<int>
     */
    private function gpsFuerScope(Team $team, string $scope, string $ref): array
    {
        $sichtbar = fn () => FoodAlchemistGp::visibleToTeam($team);
        if ($scope === 'gp') {
            return $sichtbar()->whereKey((int) $ref)->pluck('foodalchemist_gps.id')->map(fn ($v) => (int) $v)->all();
        }
        if (! in_array($scope, ['artikel', 'warengruppe', 'lieferant'], true)) {
            return [];
        }

        // wirksamer Lead je GP: Pin des Teams, sonst der Plattform-Lead
        $pins = DB::table('foodalchemist_gp_la_preferences')->where('team_id', $team->id)->where('gepinnt', true)->whereNull('deleted_at')
            ->pluck('supplier_item_id', 'gp_id')->map(fn ($v) => (int) $v)->all();
        $kandidaten = $sichtbar()->where(fn ($w) => $w->whereNotNull('lead_la_supplier_item_id')->orWhereIn('foodalchemist_gps.id', array_keys($pins)))
            ->when($scope === 'warengruppe', fn ($q) => $q->where('commodity_group_code', $ref))
            ->get(['foodalchemist_gps.id', 'lead_la_supplier_item_id']);
        $effektiv = [];
        foreach ($kandidaten as $g) {
            $la = $pins[(int) $g->id] ?? ($g->lead_la_supplier_item_id !== null ? (int) $g->lead_la_supplier_item_id : null);
            if ($la !== null) {
                $effektiv[(int) $g->id] = $la;
            }
        }

        return match ($scope) {
            'warengruppe' => array_map('intval', array_keys($effektiv)),
            'artikel' => array_map('intval', array_keys(array_filter($effektiv, fn (int $la) => $la === (int) $ref))),
            // Spec 82 §12 A3: Stamm-Paket-Artikel ohne Opt-in zählt nicht (visibleToTeam der Artikel)
            'lieferant' => (function () use ($team, $effektiv, $ref) {
                $laIds = FoodAlchemistSupplierItem::visibleToTeam($team)->whereIn('foodalchemist_supplier_items.id', array_values(array_unique($effektiv)))
                    ->where('supplier_id', (int) $ref)->pluck('foodalchemist_supplier_items.id')->map(fn ($v) => (int) $v)->all();

                return array_map('intval', array_keys(array_filter($effektiv, fn (int $la) => in_array($la, $laIds, true))));
            })(),
        };
    }

    /**
     * Ersatzvorschläge aus dem Äquivalenz-Katalog (component_equivalents) für die
     * betroffenen GPs. Katalog ist heute dünn befüllt → oft leer; die Strecke steht.
     *
     * @param  list<int>  $gpIds
     * @return list<array{gp_id:int,alt_kind:string,alt_id:int,alt_name:string}>
     */
    private function substitutionen(array $gpIds): array
    {
        if ($gpIds === []) {
            return [];
        }
        $eq = DB::table('foodalchemist_component_equivalents')
            ->whereNull('deleted_at')
            ->where('source_kind', 'gp')->whereIn('source_id', $gpIds)
            ->get(['source_id', 'alt_kind', 'alt_id']);

        $out = [];
        foreach ($eq as $e) {
            $name = $e->alt_kind === 'gp'
                ? DB::table('foodalchemist_gps')->where('id', $e->alt_id)->value('name')
                : DB::table('foodalchemist_recipes')->where('id', $e->alt_id)->value('name');
            $out[] = [
                'gp_id' => (int) $e->source_id, 'alt_kind' => (string) $e->alt_kind,
                'alt_id' => (int) $e->alt_id, 'alt_name' => $name ?? ('#' . $e->alt_id),
            ];
        }

        return $out;
    }
}
