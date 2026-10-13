<?php

declare(strict_types=1);

namespace Platform\FoodAlchemist\Support;

use Platform\Core\Models\Team;

/**
 * Spec 82 §13 I (Grundsatz (a)) — Basis-Kataloge: global (`team_id` NULL), alle lesen, NUR der Master schreibt, das Team ergänzt eigene Zeilen.
 *
 * Gilt für die Kataloge, auf die Master-Inhalt zeigt (Rezept-Kategorien, Gerichte-Klassen, Aufschlagsklassen, Einheiten, Warengruppen,
 * Servierformen, Servier-Vehikel, Koch-Equipment, Behälter, Regenerationsgeräte, Komponenten-Äquivalenz …). Lesen läuft über
 * `visibleToTeam`/`applyVisible` (global + eigene Kette); hier steht nur das SCHREIBRECHT.
 *
 * Streng über `MasterTeam::istMaster` — ohne den Rückfall „elternloses Team = Master" von `TeamScope::isMaster` (auf demo sind alle Teams
 * elternlos). Ohne konfiguriertes Master-Team schreibt niemand global (fail closed), eigene Zeilen bleiben schreibbar.
 */
final class BasisKatalog
{
    /** Eigene Zeile (Besitzer-Team) oder globale Zeile (team_id NULL) als Master. Fremde Teams: nie. */
    public static function mayWrite(mixed $rowTeamId, ?Team $team): bool
    {
        return TeamScope::owns($rowTeamId, $team) || ($rowTeamId === null && MasterTeam::istMaster($team));
    }
}
