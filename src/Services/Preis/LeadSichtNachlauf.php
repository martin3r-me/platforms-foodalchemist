<?php

namespace Platform\FoodAlchemist\Services\Preis;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\RecomputeTeamRecipesJob;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamStammOptin;
use Platform\FoodAlchemist\Services\KostenCacheService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Support\StammPaket;

/**
 * Lead-Sicht (Kurator 29.10.2026): Nachlauf für Mandanten, deren globaler Lead-Artikel für sie nicht sichtbar ist (Stamm-Lieferant nicht
 * freigeschaltet). Bisher rechnete ihr EK dann den Ø aller sichtbaren Artikel, jetzt den Ersatz nach Rangliste ({@see LieferantenAusweich::leadUebergehen}).
 * `vorschau()` schreibt nichts (Befehl ohne --apply, MCP lead_sicht.GET); `anwenden()` markiert die Team-Kosten veraltet und rechnet die eigenen Rezepte neu.
 */
class LeadSichtNachlauf
{
    public function __construct(private RecipeRecomputeService $recompute, private KostenCacheService $kosten) {}

    /**
     * Je Mandant: GPs mit unsichtbarem Lead, die in Rezepten stehen, samt künftigem effektivem Lead; betroffene eigene Rezepte und Team-Kosten-Zeilen.
     *
     * @return list<array{team_id:int, team:string, gps:list<array<string,mixed>>, rezepte_eigen:list<int>, kosten_zeilen:int}>
     */
    public function vorschau(?Team $nurTeam = null): array
    {
        $out = [];
        foreach ($this->mandanten($nurTeam) as $team) {
            $gps = FoodAlchemistGp::visibleToTeam($team)->whereNotNull('lead_la_supplier_item_id')
                ->whereNotIn('lead_la_supplier_item_id', FoodAlchemistSupplierItem::visibleToTeam($team)->select('foodalchemist_supplier_items.id'))
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('foodalchemist_recipe_ingredients AS ri')
                    ->whereColumn('ri.gp_id', 'foodalchemist_gps.id')->whereNull('ri.deleted_at'))
                ->orderBy('name')->get(['foodalchemist_gps.id', 'name', 'lead_la_supplier_item_id']);
            if ($gps->isEmpty()) {
                continue;
            }
            $zeilen = [];
            foreach ($gps as $gp) {
                $neu = $this->recompute->effektiverLeadFuer($gp, $team);
                $neu = $neu !== null && $neu !== (int) $gp->lead_la_supplier_item_id ? $neu : null;   // null = kein sichtbarer Ersatz → Ø bzw. unbepreist
                $zeilen[] = ['gp_id' => (int) $gp->id, 'gp' => $gp->name, 'lead_la_id' => (int) $gp->lead_la_supplier_item_id,
                    'lead_lieferant' => $this->lieferant((int) $gp->lead_la_supplier_item_id), 'neu_la_id' => $neu, 'neu_lieferant' => $neu !== null ? $this->lieferant($neu) : null];
            }
            $teamIds = RecomputeTeamRecipesJob::teamUndNachfahren((int) $team->id);
            $rezepte = $this->kosten->rezepteMitGps($gps->pluck('id')->map(fn ($i) => (int) $i)->all());
            $out[] = [
                'team_id' => (int) $team->id, 'team' => (string) $team->name, 'gps' => $zeilen,
                'rezepte_eigen' => DB::table('foodalchemist_recipes')->whereIn('id', $rezepte)->whereIn('team_id', $teamIds)->whereNull('deleted_at')
                    ->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all(),
                'kosten_zeilen' => DB::table('foodalchemist_recipe_team_kosten')->whereIn('recipe_id', $rezepte)->whereIn('team_id', $teamIds)->count(),
            ];
        }

        return $out;
    }

    /** @return array{teams:int, kosten_veraltet:int, rezepte_neu:int, fehler:array<int,string>} */
    public function anwenden(?Team $nurTeam = null): array
    {
        $erg = ['teams' => 0, 'kosten_veraltet' => 0, 'rezepte_neu' => 0, 'fehler' => []];
        foreach ($this->vorschau($nurTeam) as $t) {
            $erg['teams']++;
            $erg['kosten_veraltet'] += $this->kosten->markiereVeraltet($this->kosten->rezepteMitGps(array_column($t['gps'], 'gp_id')),
                RecomputeTeamRecipesJob::teamUndNachfahren($t['team_id']));
            $fehler = [];
            $erg['rezepte_neu'] += count($this->recompute->recomputeMany($t['rezepte_eigen'], $fehler));
            $erg['fehler'] += $fehler;
        }

        return $erg;
    }

    /** Mandanten = Teams mit Stamm-Paket, ohne die Master-Kette (die sieht alle Lieferanten, dort ändert sich nichts). @return list<Team> */
    private function mandanten(?Team $nurTeam): array
    {
        $teams = $nurTeam !== null ? [$nurTeam]
            : Team::whereIn('id', FoodAlchemistTeamStammOptin::query()->select('team_id'))->orderBy('id')->get()->all();

        return array_values(array_filter($teams, fn (Team $t) => StammPaket::istFreigeschaltet($t) && ! StammPaket::sieheAlleLieferanten($t)));
    }

    private function lieferant(int $laId): ?string
    {
        return DB::table('foodalchemist_supplier_items AS i')->leftJoin('foodalchemist_suppliers AS s', 's.id', '=', 'i.supplier_id')
            ->where('i.id', $laId)->value('s.name');
    }
}
