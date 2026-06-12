<?php

namespace Platform\FoodAlchemist\Policies;

use Illuminate\Database\Eloquent\Model;
use Platform\FoodAlchemist\Support\Curate;

/**
 * M8-02: Generische Modul-Policy — bildet die D1-Regeln als Gate ab und
 * nutzt DIESELBEN Regel-Stellen wie die Services (eine Wahrheit):
 *   view   = visibleToTeam (Team-Kette aufwärts, global team_id NULL)
 *   update/delete = Curate::canCurate (NUR das Besitzer-Team, M1-08)
 * Registriert für alle Kern-Models im ServiceProvider.
 */
class FoodAlchemistPolicy
{
    public function view(object $user, Model $model): bool
    {
        $team = method_exists($user, 'currentTeamRelation') ? $user->currentTeamRelation : null;
        if ($team === null) {
            return false;
        }

        return $model::visibleToTeam($team)->whereKey($model->getKey())->exists();
    }

    public function update(object $user, Model $model): bool
    {
        return Curate::canCurate($user, $model);
    }

    public function delete(object $user, Model $model): bool
    {
        return Curate::canCurate($user, $model);
    }
}
