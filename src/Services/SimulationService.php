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
     * @param  string  $scope  'warengruppe' | 'artikel' | 'gp' | 'lieferant'
     * @param  string  $ref    WG-Code | supplier_item_id | gp_id | supplier_id
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
        // Spalten qualifiziert: der Scope „lieferant" joint die Artikel-Tabelle dazu, und die
        // trägt team_id/deleted_at ebenfalls — unqualifiziert wirft SQLite „ambiguous column".
        $q = DB::table('foodalchemist_gps')
            ->whereIn('foodalchemist_gps.team_id', $ancestry)
            ->whereNull('foodalchemist_gps.deleted_at');

        return match ($scope) {
            'gp' => [(int) $ref],
            'artikel' => $q->where('lead_la_supplier_item_id', (int) $ref)
                ->pluck('foodalchemist_gps.id')->map(fn ($v) => (int) $v)->all(),
            'warengruppe' => $q->where('commodity_group_code', $ref)->whereNotNull('lead_la_supplier_item_id')
                ->pluck('foodalchemist_gps.id')->map(fn ($v) => (int) $v)->all(),
            // Spec 32: „Lieferant X erhöht um 5 %" — die praxisnächste Frage im Einkauf und
            // bis dahin die einzige, die sich NICHT simulieren ließ. Getroffen sind alle GPs,
            // deren Lead-Artikel bei diesem Lieferanten liegt; wer woanders bezieht, ist es nicht.
            'lieferant' => $q->join('foodalchemist_supplier_items as li', 'li.id', '=', 'foodalchemist_gps.lead_la_supplier_item_id')
                ->where('li.supplier_id', (int) $ref)->whereNull('li.deleted_at')
                ->pluck('foodalchemist_gps.id')->map(fn ($v) => (int) $v)->all(),
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
