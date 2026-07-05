<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;

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
     * @param  string  $scope  'warengruppe' | 'artikel' | 'gp'
     * @param  string  $ref    WG-Code | supplier_item_id | gp_id
     * @return array{scope:string,ref:string,delta_pct:float,ratio:float,n_gps:int,n_recipes:int,n_gerichte:int,n_concepts:int,marge_delta_eur:float,top:list<array>,substitutions:list<array>}
     */
    public function simuliere(Team $team, string $scope, string $ref, float $deltaPct): array
    {
        $ratio = 1 + $deltaPct / 100;
        $gpIds = $this->gpsFuerScope($team, $scope, $ref);

        $res = $this->impact->impactFuerGps($team, $gpIds, $ratio);
        $res['scope'] = $scope;
        $res['ref'] = $ref;
        $res['delta_pct'] = $deltaPct;
        $res['ratio'] = round($ratio, 4);
        $res['substitutions'] = $this->substitutionen($gpIds);

        return $res;
    }

    /** GP-ids für den Szenario-Scope (Team-Kette, nur mit Lead-LA — nur die treiben EK). */
    private function gpsFuerScope(Team $team, string $scope, string $ref): array
    {
        $ancestry = FoodAlchemistGp::teamAncestryIds($team);
        $q = DB::table('foodalchemist_gps')->whereIn('team_id', $ancestry)->whereNull('deleted_at');

        return match ($scope) {
            'gp' => [(int) $ref],
            'artikel' => $q->where('lead_la_supplier_item_id', (int) $ref)->pluck('id')->map(fn ($v) => (int) $v)->all(),
            'warengruppe' => $q->where('commodity_group_code', $ref)->whereNotNull('lead_la_supplier_item_id')
                ->pluck('id')->map(fn ($v) => (int) $v)->all(),
            default => [],
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
