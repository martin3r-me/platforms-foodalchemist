<?php

namespace Platform\FoodAlchemist\Support;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\MandantenService;

/**
 * Spec 82 §13 G — darf `$betrachter` den Marken-Kontext des Teams `$ziel` sehen?
 *
 *  - das eigene Team, und das Oberteam für seine Unter-Teams (Brille): ja
 *  - das Master-Team für KURATIERTE Mandanten: ja (lesend)
 *  - alles andere: nein — auch der Plattform-Admin sieht bei AUTARKEN Mandanten keinen Inhalt;
 *    Admin-Rechte öffnen hier nichts (Pflicht-Test §13 G: 0 Inhalt über Oberfläche und MCP).
 */
final class KontextSicht
{
    public static function darf(Team $betrachter, Team $ziel): bool
    {
        if ((int) $betrachter->id === (int) $ziel->id) {
            return true;
        }
        $rechte = app(FaRechte::class);
        if (in_array((int) $betrachter->id, $rechte->teamKette($ziel), true)) {
            return true;   // Oberteam → Unter-Team
        }

        return MasterTeam::istMaster($betrachter)
            && ! MasterTeam::istMaster($ziel)
            && app(MandantenService::class)->modusVon($ziel) === 'kuratiert';
    }
}
