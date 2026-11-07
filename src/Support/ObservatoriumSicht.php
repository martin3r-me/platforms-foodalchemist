<?php

namespace Platform\FoodAlchemist\Support;

use Platform\Core\Models\Team;

/**
 * Observatorium zentral (Dominique 07.11.2026): BHG.DIGITAL kuratiert das Gastro-Observatorium für ALLE Mandanten.
 *
 * - Lesen: Jeder Mandant sieht die Inhalte des Master-Teams (Eingang, Vertiefung, Trends, Markt in Zahlen, Lagebild) —
 *   auch Root-Teams, die nicht unter dem Master hängen. Die Rechteprüfung „Lesen“ läuft weiter am eigenen Team,
 *   gelesen wird beim Inhalts-Team. Persönliche Merker bleiben am eigenen Team.
 * - Kuratieren / Trend Research: nur im Master-Team (Bereich `trend_research` in {@see FaBereiche::NUR_BEIM_MASTER}).
 * - Ohne konfiguriertes Master-Team bleibt alles beim eigenen Team (autarke Installation).
 * - Wettbewerber sind weggelassen (Ertrag 0 Funde): {@see self::WETTBEWERB_AKTIV}. Daten und Code bleiben, nur Ansicht und Tools sind aus.
 */
final class ObservatoriumSicht
{
    public const WETTBEWERB_AKTIV = false;

    /** Team, dessen Observatorium-Inhalte gelesen werden: das Master-Team, sonst das eigene. */
    public static function inhaltsTeam(Team $team): Team
    {
        $id = MasterTeam::id();
        if ($id === null || (int) $team->id === $id) {
            return $team;
        }

        return Team::find($id) ?? $team;
    }

    /** Darf dieses Team kuratieren (Trend Research, Vertiefen, Einordnen, Pinnwand, Lagebild schreiben)? */
    public static function kuratiertHier(Team $team): bool
    {
        return MasterTeam::id() === null || MasterTeam::istMaster($team);
    }
}
