<?php

namespace Platform\FoodAlchemist\Support;

use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\MandantenService;

/**
 * Spec 82 §13 G — Betreiber und Kurator getrennt: Ein Plattform-Admin ohne echte Mitgliedschaft sieht in einem Kunden-Team KEINE Inhalte.
 *
 * Anlass (Befund Peter, gemessen): `FaRechte::rolle()` machte den Plattform-Admin in JEDEM Team zum FA-Admin, und Inhaltslesen hängt nur am
 * Team-Kontext, nicht am Benutzer. Setzt Core den Team-Kontext ohne Mitgliedschaftsprüfung (Core `TeamFlyout::switchTeam`), sah der Admin
 * nach dem Wechsel Rezepte, Preise und Foodbooks eines autarken Mandanten. Core wird nicht angefasst; die Sperre sitzt im Modul.
 *
 * Gesperrt ist die Kombination (Plattform-Admin, Kunden-Team), wenn der Admin
 *  - kein echtes team_user-Mitglied des Teams oder eines Eltern-Teams ist UND
 *  - nicht als Mitglied des Master-Teams in einem KURATIERTEN Mandanten arbeitet (dann gilt lesend, siehe `FaRechte::rolle()`).
 * Das Master-Team ist kein Kunden-Team und nie gesperrt. Normale Benutzer sind nicht betroffen (Core lässt sie nur in Teams ihrer Mitgliedschaft).
 *
 * Eine Stelle, drei Durchsetzungspunkte wie {@see TeamSperre}: {@see \Platform\FoodAlchemist\Http\Middleware\FaBereichMiddleware} (Seiten),
 * der Livewire-`call`-Haken im ServiceProvider und {@see \Platform\FoodAlchemist\Tools\FaRechteToolHuelle} (MCP). Die Plattform-Steuerung
 * (/verwaltung, Plattform-Tools) bleibt für den Admin erreichbar: Sie zeigt nur Verwaltungsdaten.
 */
final class InhaltsSperre
{
    /** Route-Segmente nach „foodalchemist.“, die auch gesperrt erreichbar bleiben (gleiche Liste wie bei der Vollsperre). */
    public const ROUTEN_AUSNAHME = TeamSperre::ROUTEN_AUSNAHME;

    /** Livewire-Segmente unter Platform\FoodAlchemist\Livewire\, die auch gesperrt laufen: die in /verwaltung eingebetteten Komponenten. */
    public const KOMPONENTEN_AUSNAHME = ['Verwaltung', 'Export'];

    /** MCP-Tool-Präfixe der Plattform-Steuerung: Verwaltungsdaten, keine Inhalte (§13 G Tabelle „Darf sehen“). */
    public const TOOLS_AUSNAHME = [
        'foodalchemist.mandanten.', 'foodalchemist.mandanten_beenden.', 'foodalchemist.stamm_paket.', 'foodalchemist.master_team.',
        'foodalchemist.mandant_export.', 'foodalchemist.plan_preise.', 'foodalchemist.plan_nutzung.', 'foodalchemist.team_bereiche.',
    ];

    public const MELDUNG = 'Kein Zugriff auf die Inhalte dieses Teams: Als Plattform-Admin siehst du je Mandant nur Verwaltungsdaten (Spec 82 §13 G). '
        .'Inhalte siehst du nur als Mitglied des Teams. Die Plattform-Verwaltung bleibt erreichbar.';

    /**
     * Ist `$team` für `$user` ein gesperrter Inhaltskontext? Nur Plattform-Admins können betroffen sein; für alle anderen sofort false
     * (keine Abfrage). Ohne Team oder Benutzer false.
     */
    public static function istGesperrt(?User $user, ?Team $team): bool
    {
        if ($user === null || $team === null || $user->isAiUser()) {
            return false;
        }
        $rechte = app(FaRechte::class);
        if (! $rechte->istPlattformAdmin($user)) {
            return false;
        }
        if (MasterTeam::istMaster($team) || MasterTeam::istMaster($rechte->kundenHauptTeam($team))) {
            return false;   // das Master-Team ist kein Kunden-Team
        }
        if ($rechte->istMitglied($user, $team)) {
            return false;   // echte team_user-Mitgliedschaft (Team oder Eltern-Team)
        }

        return ! self::masterLesendBeiKuratiert($user, $team);
    }

    /** Mitglied des Master-Teams, das einen KURATIERTEN Mandanten liest (vertraglich, §13 G): nicht gesperrt, aber nur lesend (FaRechte::rolle). */
    public static function masterLesendBeiKuratiert(User $user, Team $team): bool
    {
        $masterId = MasterTeam::id();
        $master = $masterId !== null ? Team::find($masterId) : null;

        $rechte = app(FaRechte::class);

        // Nur, wenn er NICHT zugleich echtes Mitglied des Mandanten ist (dann gilt seine Mitglieds-Rolle voll).
        return $master !== null && ! $rechte->istMitglied($user, $team) && $rechte->istMitglied($user, $master)
            && app(MandantenService::class)->modusVon($team) === 'kuratiert';
    }

    public static function routeErlaubt(?string $routeName): bool
    {
        return TeamSperre::routeErlaubt($routeName);
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
}
