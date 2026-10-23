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

    /**
     * Führt $fn aus und rechnet darin entstehende KI-Aufrufe dem Team zu. Seit Spec 84 A-3 nur noch eine dünne Hülle um
     * {@see FaTeam::imTeam} — EIN Kontext für Kill-Switch, Budget, Call-Log und Abrechnung statt eines eigenen Statics hier.
     * `KONTEXT_PLATTFORM` (0) = ausdrücklich ohne Team (Plattform-Lauf), `null` = Kontext unverändert lassen.
     */
    public static function imKontext(?int $teamId, callable $fn): mixed
    {
        if ($teamId === null) {
            return $fn();
        }

        return FaTeam::imTeam($teamId === self::KONTEXT_PLATTFORM ? null : $teamId, $fn);
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
        // Reihenfolge: ausdrückliche Option > FaTeam (Job-/MCP-Team schlägt einen alten Auth::login des Workers, Spec 84 A-3).
        // „Ausdrücklich ohne Team“ (Plattform-Lauf, imKontext(KONTEXT_PLATTFORM)) bleibt Plattform, auch mit altem Login.
        if ($ausdruecklich === self::KONTEXT_PLATTFORM || ($ausdruecklich === null && FaTeam::ausdruecklich() && FaTeam::aktuell() === null)) {
            return ['abrechnung_team_id' => null, 'verursacher' => self::PLATTFORM];
        }

        $team = $ausdruecklich !== null ? Team::find($ausdruecklich) : FaTeam::aktuell();   // Spec 84 A-2: MCP-/Job-Team statt UI-Team

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

    /**
     * Zahlendes Kunden-Hauptteam: dieselbe Kundengrenze wie Kontingente und Bereiche ({@see \Platform\FoodAlchemist\Services\FaRechte::kundenHauptTeam}) —
     * oberstes Team der Kette, aber nie über das Master-Team hinaus. Hängt ein Kunde unter dem Master, zahlt er selbst (vorher lief seine KI
     * als „plattform“ und war weder abgerechnet noch gedeckelt, Kurator 23.10.).
     */
    public static function hauptteam(Team $team): Team
    {
        return app(\Platform\FoodAlchemist\Services\FaRechte::class)->kundenHauptTeam($team);
    }
}
