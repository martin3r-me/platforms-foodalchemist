<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\PaketGrundeinstellungenService;
use Platform\FoodAlchemist\Services\TeamSettingsService;

/**
 * Spec 83 B2/B2a/B3g: EINE Regel, welcher Küchenposten für ein Rezept gilt — geteilt von den Auftragszeilen
 * ({@see \Platform\FoodAlchemist\Services\ProductionOrderService}) und der Verteilung der Gerichts-Blöcke auf Posten
 * ({@see \Platform\FoodAlchemist\Services\ProductionTimeService::postenAnteile}). Aus ProductionOrderService::standardPosten
 * verschoben (Verhalten 1:1), damit die beiden Stellen nicht still auseinanderlaufen.
 *
 * Routing-Reihenfolge: `recipes.default_station_id` (eigen/sichtbar, sonst die Paket-Kopie des Master-Postens, B2a) > bei Gerichten
 * (`is_sales_recipe`) die Team-Vorgabe "Posten für Gerichte" ({@see TeamSettingsService::gerichtePostenId}, Pass) > keiner.
 * Alles nur mit Posten, die für das Team sichtbar und aktiv sind (`visibleToTeam`). Ein Rezept-Posten eines FREMDEN Teams wird nicht
 * still übernommen; greift danach auch keine Team-Vorgabe, bleibt das Rezept ohne Posten und landet in der Liste der fremden Namen.
 */
class PostenAufloesung
{
    public function __construct(private TeamSettingsService $settings) {}

    /**
     * @param  Collection<int, \Platform\FoodAlchemist\Models\FoodAlchemistRecipe>  $rezepte  mit id, name, default_station_id, is_sales_recipe
     * @return array{0: array<int,int>, 1: list<string>}  [recipe_id => station_id, Namen der Rezepte mit fremdem Posten ohne Ersatz]
     */
    public function fuerRezepte(Team $besitzer, Collection $rezepte): array
    {
        if ($rezepte->isEmpty()) {
            return [[], []];
        }
        $vorgabe = $this->settings->gerichtePostenId($besitzer);
        $kandidaten = $rezepte->pluck('default_station_id')->filter()->map(fn ($i) => (int) $i)->all();
        $sichtbar = $kandidaten === [] ? collect() : FoodAlchemistProductionStation::visibleToTeam($besitzer)->where('is_inactive', false)
            ->whereIn('id', $kandidaten)->pluck('id')->map(fn ($i) => (int) $i)->flip();

        // Spec 83 B2a: ein Master-Posten (z. B. am Rezept im Abo) ist für den Mandanten fremd, aber das Paket "Grundeinstellungen" hat ihn kopiert:
        // paket_kopien kennt Master-Posten → Kopie im Haupt-Team des Mandanten. Nur diese Zuordnung löst auf, nie ein Raten über den Namen.
        $kopien = $this->postenKopien($besitzer, array_values(array_diff($kandidaten, $sichtbar->keys()->all())));

        $posten = [];
        $fremd = [];
        foreach ($rezepte as $r) {
            $eigen = $r->default_station_id !== null ? (int) $r->default_station_id : null;
            if ($eigen !== null && isset($sichtbar[$eigen])) {
                $posten[(int) $r->id] = $eigen;
            } elseif ($eigen !== null && isset($kopien[$eigen])) {
                $posten[(int) $r->id] = $kopien[$eigen];       // Master-Posten → Kopie des Teams (Paket)
            } elseif ($r->is_sales_recipe && $vorgabe !== null) {
                $posten[(int) $r->id] = $vorgabe;            // Gericht ohne (nutzbaren) Rezept-Posten: Pass
            } elseif ($eigen !== null) {
                $fremd[] = (string) $r->name;
            }
        }

        return [$posten, $fremd];
    }

    /**
     * Master-Posten → Kopie im Team (Paket "Grundeinstellungen", `paket_kopien`). Die Kopie wird am Haupt-Team des Kunden gebucht; Unter-Teams
     * erben sie über die Kette. Eine Kopie zählt nur, wenn sie für das Team sichtbar und aktiv ist (Stilllegen/Löschen hebt die Auflösung auf).
     *
     * @param  list<int>  $quellIds  Posten-IDs, die für das Team nicht sichtbar sind
     * @return array<int,int>  quell_id => kopie_id
     */
    public function postenKopien(Team $team, array $quellIds): array
    {
        if ($quellIds === [] || ! Schema::hasTable('foodalchemist_paket_kopien')) {
            return [];
        }
        $teamIds = array_values(array_unique([(int) $team->id, (int) app(FaRechte::class)->kundenHauptTeam($team)->id]));
        $zuordnung = DB::table('foodalchemist_paket_kopien')->whereIn('team_id', $teamIds)->where('paket', PaketGrundeinstellungenService::PAKET)
            ->where('tabelle', 'production_stations')->whereIn('quell_id', $quellIds)->pluck('kopie_id', 'quell_id');
        if ($zuordnung->isEmpty()) {
            return [];
        }
        $nutzbar = FoodAlchemistProductionStation::visibleToTeam($team)->where('is_inactive', false)->whereIn('id', $zuordnung->values()->all())->pluck('id')->map(fn ($i) => (int) $i)->flip();

        return $zuordnung->filter(fn ($kopie) => isset($nutzbar[(int) $kopie]))->map(fn ($kopie) => (int) $kopie)->mapWithKeys(fn ($kopie, $quell) => [(int) $quell => $kopie])->all();
    }

    /**
     * Spec 83 B3g: Sammelposten-Merkmal der (aufgelösten) Posten — ein Sammelposten (Pass) hat kein eigenes Personal.
     *
     * @param  list<int>  $ids
     * @return array<int,bool>  station_id => ist_sammelposten
     */
    public function sammelposten(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids === [] ? [] : FoodAlchemistProductionStation::whereIn('id', $ids)->pluck('ist_sammelposten', 'id')
            ->mapWithKeys(fn ($v, $id) => [(int) $id => (bool) $v])->all();
    }
}
