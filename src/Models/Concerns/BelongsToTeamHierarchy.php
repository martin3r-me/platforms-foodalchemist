<?php

namespace Platform\FoodAlchemist\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Platform\Core\Models\Team;

/**
 * D1 (revidiert 2026-06-11): Eltern→Kinder-Katalog-Vererbung statt NULL-Global.
 *
 * Modell wie beim echten Caterer/Gastronomen: das Eltern-Team pflegt den Katalog
 * (GPs, LAs, Preise, Basisrezept-Vorlagen), Kind-Teams erben ihn lesend über die
 * Eltern-Kette und legen eigene Datensätze in ihrem Team an.
 *
 * - Sichtbar  = team_id ∈ {eigenes Team, alle Eltern bis Root}  → scopeVisibleToTeam()
 * - Editierbar = team_id == aktuelles Team                       → isOwnedBy()
 *
 * Einzel-Gastronom (1 Team, keine Kinder) und Konzern (Root + n Betriebe)
 * funktionieren damit ohne Sonderfall. Nutzt Cores teams.parent_team_id.
 */
trait BelongsToTeamHierarchy
{
    public function scopeVisibleToTeam(Builder $query, Team $team): Builder
    {
        return $query->whereIn($query->getModel()->getTable() . '.team_id', self::teamAncestryIds($team));
    }

    public function isOwnedBy(Team $team): bool
    {
        return (int) $this->team_id === (int) $team->id;
    }

    /**
     * Team-Kette aufwärts (eigenes Team zuerst, Root zuletzt), je Request gecacht.
     *
     * @return array<int>
     */
    public static function teamAncestryIds(Team $team): array
    {
        static $cache = [];

        if (!isset($cache[$team->id])) {
            $ids = [];
            $current = $team;
            $guard = 0;
            while ($current && $guard < 32) { // Zyklen-Schutz
                $ids[] = (int) $current->id;
                $current = $current->parent_team_id ? $current->parentTeam : null;
                $guard++;
            }
            $cache[$team->id] = $ids;
        }

        return $cache[$team->id];
    }
}
