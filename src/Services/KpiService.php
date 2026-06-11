<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;

/**
 * M0-13 / P-1-Header: KPI-Zähler je Team-Kette (D1-Sichtbarkeit), 60 s gecacht.
 *
 * `rezepte` ist NULL, bis die Rezept-Welt existiert (M4-01) — der hasTable-Guard
 * aktiviert den Zähler dann ohne Code-Änderung.
 */
class KpiService
{
    public const CACHE_SECONDS = 60;

    /**
     * @return array{lieferanten: int, gps: int, las: int, rezepte: int|null}
     */
    public function forTeam(?Team $team): array
    {
        if ($team === null) {
            return ['lieferanten' => 0, 'gps' => 0, 'las' => 0, 'rezepte' => null];
        }

        return Cache::remember(
            "foodalchemist.kpis.team.{$team->id}",
            self::CACHE_SECONDS,
            fn () => [
                'lieferanten' => FoodAlchemistSupplier::visibleToTeam($team)->count(),
                'gps' => FoodAlchemistGp::visibleToTeam($team)->count(),
                // LAs = kuratierte LA-Strukturen (Ist-App-Header zählt 9.803), nicht der Roh-Katalog
                'las' => FoodAlchemistSupplierItemStructure::visibleToTeam($team)->count(),
                'rezepte' => Schema::hasTable('foodalchemist_recipes')
                    ? DB::table('foodalchemist_recipes')
                        ->whereIn('team_id', FoodAlchemistGp::teamAncestryIds($team))
                        ->whereNull('deleted_at')
                        ->count()
                    : null,
            ],
        );
    }

    public function flush(Team $team): void
    {
        Cache::forget("foodalchemist.kpis.team.{$team->id}");
    }
}
