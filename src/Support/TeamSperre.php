<?php

namespace Platform\FoodAlchemist\Support;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistMandant;
use Platform\FoodAlchemist\Services\FaRechte;

/**
 * Spec 82 §13 C3 — VOLLSPERRE eines beendeten Mandanten. Gesperrt ist ein Team, wenn sein Kunden-Hauptteam den Status
 * „beendet“ trägt (Unter-Teams erben); das Master-Team ist nie gesperrt. Gesperrt heißt: KEIN FA-Zugriff, nirgends —
 * Seiten, Livewire-Aktionen und MCP-Tools —, außer den Ausnahmen unten (Export-Download, Plattform-Steuerung,
 * öffentliche Präsentationen). Rückgängig = Status zurück auf „aktiv“ (die Bereiche bleiben aus, bis sie neu gebucht werden).
 *
 * Eine Stelle, drei Durchsetzungspunkte: {@see \Platform\FoodAlchemist\Http\Middleware\FaBereichMiddleware} (Seiten),
 * der Livewire-`call`-Haken im ServiceProvider und {@see \Platform\FoodAlchemist\Tools\FaRechteToolHuelle} (MCP).
 * Es gibt KEINE Ausnahme für Plattform-Admins (§13 G): Sie steuern über die Verwaltung, nicht über die Inhalte.
 */
final class TeamSperre
{
    /** Route-Segmente nach „foodalchemist.“, die auch gesperrt erreichbar bleiben. */
    public const ROUTEN_AUSNAHME = ['presentation', 'platform-asset', 'mandant-export'];

    /** Livewire-Segmente (unter Platform\FoodAlchemist\Livewire\), die auch gesperrt laufen. */
    public const KOMPONENTEN_AUSNAHME = ['Verwaltung', 'Export'];

    /** MCP-Tool-Präfixe, die auch gesperrt laufen: Plattform-Steuerung (Wiederfreigabe!) und der Export. */
    public const TOOLS_AUSNAHME = [
        'foodalchemist.mandanten.', 'foodalchemist.mandanten_beenden.', 'foodalchemist.stamm_paket.',
        'foodalchemist.master_team.', 'foodalchemist.mandant_export.',
    ];

    /** @var array<int, bool> */
    private static array $cache = [];

    public static function flush(): void
    {
        self::$cache = [];
    }

    public static function istGesperrt(?Team $team): bool
    {
        if ($team === null) {
            return false;
        }
        $id = (int) $team->id;
        if (! array_key_exists($id, self::$cache)) {
            $haupt = app(FaRechte::class)->kundenHauptTeam($team);
            self::$cache[$id] = ! MasterTeam::istMaster($haupt)
                && FoodAlchemistMandant::where('team_id', $haupt->id)->value('status') === FoodAlchemistMandant::STATUS_BEENDET;
        }

        return self::$cache[$id];
    }

    public static function routeErlaubt(?string $routeName): bool
    {
        if ($routeName === null || ! str_starts_with($routeName, 'foodalchemist.')) {
            return true;   // keine FA-Route
        }
        $segment = explode('.', substr($routeName, strlen('foodalchemist.')))[0];

        return in_array($segment, self::ROUTEN_AUSNAHME, true);
    }

    public static function komponenteErlaubt(object|string $komponente): bool
    {
        $klasse = is_object($komponente) ? $komponente::class : $komponente;
        $praefix = 'Platform\\FoodAlchemist\\Livewire\\';
        if (! str_starts_with($klasse, $praefix)) {
            return true;   // keine FA-Komponente
        }

        return in_array(explode('\\', substr($klasse, strlen($praefix)))[0], self::KOMPONENTEN_AUSNAHME, true);
    }

    public static function toolErlaubt(string $toolName): bool
    {
        foreach (self::TOOLS_AUSNAHME as $praefix) {
            if (str_starts_with($toolName, $praefix)) {
                return true;
            }
        }

        return false;
    }

    public const MELDUNG = 'Dieses Team ist gesperrt (Mandant beendet). Der Zugriff auf FoodAlchemist ist ausgeschaltet; nur der Export-Download bleibt erreichbar. Bei Fragen wende dich an den Betreiber.';
}
