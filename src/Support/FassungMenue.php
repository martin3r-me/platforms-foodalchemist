<?php

namespace Platform\FoodAlchemist\Support;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Spec 82 §13 B6 — Menüpunkt „Veröffentlichen …“ (Fassung an die Abonnenten geben): EINE Bedingung für Basisrezepte UND Gerichte.
 *
 * Sichtbar nur, wenn das aktive Team das Master-Team ist, das Rezept dem Master gehört und kein Fassungs-Stand ist (veröffentlicht wird die
 * Arbeitszeile, nie eine Fassung). Die Menüs in Rezept-Modal, Rezept-Detailpanel, Gericht-Modal (VK) und Gericht-Detailpanel fragen hier;
 * wer die Bedingung ändert, ändert sie überall. Das Veröffentlichen selbst prüft der {@see \Platform\FoodAlchemist\Services\FassungService}.
 */
final class FassungMenue
{
    public static function sichtbar(?Team $team, FoodAlchemistRecipe|int|null $rezept): bool
    {
        if ($team === null || $rezept === null || ! MasterTeam::istMaster($team)) {
            return false;
        }
        if (! $rezept instanceof FoodAlchemistRecipe) {
            $rezept = FoodAlchemistRecipe::query()->whereKey($rezept)->first(['id', 'team_id', 'ist_fassung']);   // Fassungen sind im Standard-Scope ausgeblendet
        }

        return $rezept !== null && (int) $rezept->team_id === (int) MasterTeam::id() && ! $rezept->ist_fassung;
    }
}
