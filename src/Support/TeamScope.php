<?php

namespace Platform\FoodAlchemist\Support;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;

/**
 * Zentrale Mandanten-Regel — Master-Vererbung (Entscheid Dominique 2026-07-12):
 * BHG.DIGITAL (Root) ist Master; Kind-Teams erben dessen Katalog + den globalen Seed (team_id NULL).
 *
 *  - Sichtbar   = team_id IS NULL (globaler Seed) ODER team_id ∈ Ancestry (eigenes + Master-Kette)
 *  - Editierbar = nur das eigene Team (Master/Seed sind für Kind-Teams read-only)
 *
 * Für die Eloquent-Modelle erledigt das der Trait BelongsToTeamHierarchy (scopeVisibleToTeam /
 * isOwnedBy). Dieser Helper ist das Pendant für ROHE DB::table-Queries (Livewire-Settings,
 * Knowledge-Service), wo die Model-Scopes nicht greifen — damit die Regel an EINER Stelle lebt.
 */
final class TeamScope
{
    /** Ancestry-IDs (eigenes Team zuerst … Root) oder [] wenn kein Team. Quelle: Trait-Cache. */
    public static function ancestryIds(?Team $team): array
    {
        return $team === null ? [] : FoodAlchemistGp::teamAncestryIds($team);
    }

    /**
     * Sichtbarkeits-Filter für rohe Query-Builder: NULL (globaler Seed) ODER Ancestry.
     * Gruppiert in einer Klammer, damit nachfolgende where() nicht am OR hängen.
     *
     * @template T of \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder
     * @param  T  $query
     * @return T
     */
    public static function applyVisible($query, string $teamIdColumn, ?Team $team)
    {
        $ids = self::ancestryIds($team);

        return $query->where(function ($q) use ($teamIdColumn, $ids) {
            $q->whereNull($teamIdColumn);
            if ($ids !== []) {
                $q->orWhereIn($teamIdColumn, $ids);
            }
        });
    }

    /** Schreibrecht: nur das Besitzer-Team; Master/Seed (team_id NULL) + Fremd-Teams sind read-only. */
    public static function owns(mixed $rowTeamId, ?Team $team): bool
    {
        return $team !== null && $rowTeamId !== null && (int) $rowTeamId === (int) $team->id;
    }
}
