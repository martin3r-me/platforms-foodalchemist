<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Database\Eloquent\Model;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\Concerns\BelongsToTeamHierarchy;

/**
 * M1-08 / D1: Katalog-Pflege-Gate — zentral für ALLE Edit-UIs.
 *
 * Editieren darf nur, wer Mitglied des Besitzer-Teams ist (currentTeam == Owner).
 * Modelle mit BelongsToTeamHierarchy: EINE Regel — `isOwnedBy(currentTeam)` (inkl. Master pflegt globale Basis-Kataloge
 * und globale stamm_paket-Zeilen). Andere Modelle: team_id == currentTeam.
 * Kind-Teams sehen geerbte Katalog-Daten read-only (D1: sichtbar = Kette aufwärts,
 * editierbar = nur Eigenes). Views fragen IMMER hierüber — nie team_id-Vergleiche
 * inline nachbauen.
 *
 *     Curate::canCurate($user, $unit)            // Model mit team_id
 *     Curate::canCurate($user, $team)               // explizites Besitzer-Team
 */
final class Curate
{
    public static function canCurate(?object $user, Model|Team|null $target): bool
    {
        if ($user === null || $target === null) {
            return false;
        }

        $currentTeamId = (int) ($user->current_team_id ?? 0);
        if ($currentTeamId === 0) {
            return false;
        }

        if ($target instanceof Model && in_array(BelongsToTeamHierarchy::class, class_uses_recursive($target), true)) {
            $aktuell = $user->currentTeamRelation ?? null;
            if (! $aktuell instanceof Team || (int) $aktuell->id !== $currentTeamId) {
                $aktuell = Team::find($currentTeamId);
            }

            return $aktuell !== null && $target->isOwnedBy($aktuell);
        }

        $ownerTeamId = $target instanceof Team
            ? (int) $target->id
            : (int) ($target->team_id ?? 0);

        return $ownerTeamId !== 0 && $ownerTeamId === $currentTeamId;
    }
}
