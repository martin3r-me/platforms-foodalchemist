<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\Auth;
use Platform\Core\Models\Team;

/**
 * Wer ist der Verursacher eines KI-Aufrufs, wenn man ihn abrechnen will (16.10.2026)?
 *
 *  - Unter-Team → sein Kunden-Hauptteam (Unter-Teams zahlen nicht selbst, wie im GebuehrenZaehler)
 *  - Master-Team (MasterTeam::id(), nie fest verdrahtet) und Läufe ohne Nutzer und ohne ausdrückliches Team → „plattform“, nicht abrechenbar
 *  - ein Massenlauf, den ein Mandanten-Nutzer ausgelöst hat, trägt dessen Team MIT (Option `verursacher_team_id`) → dem Mandanten zugerechnet
 *  - Nutzer da, aber kein Team auflösbar → „unbekannt“ (sichtbar in der Auswertung, nie still einem Mandanten zugeschlagen)
 * Ob der Mandant am Ende wirklich abgerechnet wird (Status aktiv), entscheidet die Auswertung, nicht das Log.
 */
final class KiVerursacher
{
    public const MANDANT = 'mandant';

    public const PLATTFORM = 'plattform';

    public const UNBEKANNT = 'unbekannt';

    /** Job-Kontext „Plattform“: Lauf des globalen Stamms ohne Team (schlägt einen alten Auth-Login des Workers). */
    public const KONTEXT_PLATTFORM = 0;

    /** Team, für das gerade ein Queue-Job ohne angemeldeten Nutzer arbeitet ({@see imKontext}). */
    private static ?int $kontextTeamId = null;

    /** Führt $fn aus und rechnet darin entstehende KI-Aufrufe dem Team zu (Jobs ohne Auth::login). Setzt den Kontext danach immer zurück. */
    public static function imKontext(?int $teamId, callable $fn): mixed
    {
        $vorher = self::$kontextTeamId;
        self::$kontextTeamId = $teamId;
        try {
            return $fn();
        } finally {
            self::$kontextTeamId = $vorher;
        }
    }

    /**
     * @param  array<string, mixed>  $options  verursacher_team_id (int) | verursacher = 'plattform'
     * @return array{abrechnung_team_id: ?int, verursacher: string}
     */
    public static function aufloesen(array $options = []): array
    {
        if (($options['verursacher'] ?? null) === self::PLATTFORM) {
            return ['abrechnung_team_id' => null, 'verursacher' => self::PLATTFORM];
        }

        $ausdruecklich = isset($options['verursacher_team_id']) && is_numeric($options['verursacher_team_id']) ? (int) $options['verursacher_team_id'] : null;
        // Reihenfolge: ausdrückliche Option > Team des laufenden Jobs > angemeldeter Nutzer. Der Job-Kontext schlägt den Nutzer, weil ein Queue-Worker
        // einen früheren Auth::login eines anderen Jobs behalten kann.
        $ausdruecklich ??= self::$kontextTeamId;
        if ($ausdruecklich === self::KONTEXT_PLATTFORM) {
            return ['abrechnung_team_id' => null, 'verursacher' => self::PLATTFORM];
        }

        $team = $ausdruecklich !== null ? Team::find($ausdruecklich) : Auth::user()?->currentTeamRelation;

        if ($team === null) {
            // Ohne Nutzer und ohne ausdrückliches Team: Massenlauf der Plattform. Mit Nutzer, aber ohne Team: nicht raten.
            return ['abrechnung_team_id' => null, 'verursacher' => $ausdruecklich === null && Auth::id() === null ? self::PLATTFORM : self::UNBEKANNT];
        }

        $haupt = self::hauptteam($team);
        if (MasterTeam::istMaster($haupt)) {
            return ['abrechnung_team_id' => null, 'verursacher' => self::PLATTFORM];
        }

        return ['abrechnung_team_id' => (int) $haupt->id, 'verursacher' => self::MANDANT];
    }

    /** Oberstes Team der Kette (Schutz gegen Zyklen). */
    public static function hauptteam(Team $team): Team
    {
        for ($i = 0; $i < 12 && $team->parent_team_id !== null; $i++) {
            $eltern = Team::find($team->parent_team_id);
            if ($eltern === null) {
                break;
            }
            $team = $eltern;
        }

        return $team;
    }
}
