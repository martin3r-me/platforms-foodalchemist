<?php

namespace Platform\FoodAlchemist\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Platform\Core\Models\Team;
use Platform\Core\Services\TeamContext;

/**
 * Spec 84 §3.3 · EINE Stelle für „welches Team betrachtet gerade?“ — statt currentTeamRelation (472×), Auth::login in Jobs,
 * KiVerursacher::imKontext und dem Nutzer-Klon in RecipesGenerateTool.
 *
 * Vorrang beim Lesen:
 *  1. ausdrücklich gesetzt: {@see imTeam()} bzw. im Queue-Job aus dem Payload (Laravel Context, versteckt `fa.team_id`)
 *  2. Core-Override ({@see TeamContext}: MCP-Aufruf, Tool-Loop, Playground — der Core setzt und räumt ab)
 *  3. `Auth::user()->currentTeam` (UI-Team; für FA = current_team_id, weil scope_type 'single')
 *
 * Stufe 1 vor 2 ist sicher, weil `fa.team_id` NIE stillschweigend gesetzt wird (keine Middleware), sondern nur durch imTeam()
 * oder durch die Wiederherstellung im Worker. So wirkt imTeam() auch mitten in einem MCP-Aufruf (Master-Lauf verteilt je Mandant).
 *
 * Transport in Jobs: der dehydrating-Hook im Provider schreibt das beim Versand geltende Team in den Payload — jede
 * Versand-Stelle (Livewire, MCP, imTeam) trägt damit automatisch ihr Team, ohne es zu kennen. Laravel leert den Context
 * zwischen Jobs (scoped), ein Worker erbt also kein Team vom vorigen Job.
 *
 * Merker: Team-Modelle je id und das UI-Team je Nutzer werden pro Request/Job gemerkt (scoped Instanz) — kein Query je Budget-Check.
 */
final class FaTeam
{
    public const SCHLUESSEL = 'fa.team_id';

    /** @var array<int, ?Team> */
    private array $teams = [];

    /** @var array<int, ?int> Nutzer-id → UI-Team-id */
    private array $uiTeam = [];

    public static function aktuell(): ?Team
    {
        return app(self::class)->ermittle();
    }

    public static function aktuellId(): ?int
    {
        $team = self::aktuell();

        return $team !== null ? (int) $team->id : null;
    }

    /**
     * Führt $fn im Kontext von $team aus und stellt danach den vorigen Zustand wieder her (auch bei Ausnahme).
     * Alles, was darin gelesen oder als Job versandt wird, gehört zu $team. `null` = ausdrücklich ohne Team.
     */
    public static function imTeam(Team|int|null $team, callable $fn): mixed
    {
        $id = $team instanceof Team ? (int) $team->id : $team;
        if ($team instanceof Team) {
            app(self::class)->teams[$id] = $team;
        }

        return Context::scope(function () use ($fn) {
            $ergebnis = $fn();
            // Falle: `fn () => Job::dispatch()` liefert ein PendingDispatch, und Laravel versendet erst in dessen __destruct —
            // also NACH dem Zurückstellen des Kontexts, mit dem falschen Team. Hier im Rahmen auflösen (gemessen im Spec84FaTeamTest).
            if ($ergebnis instanceof \Illuminate\Foundation\Bus\PendingDispatch) {
                $ergebnis = null;
            }

            return $ergebnis;
        }, [], [self::SCHLUESSEL => $id]);
    }

    /**
     * Wie {@see aktuellId()}, aber garantiert OHNE Datenbank-Abfrage — für Stellen, die selbst in einem Query-Listener laufen
     * (Spec 84 E-1, LangsameAbfragen). Gleicher Vorrang; Stufe 3 liest nur `current_team_id` (für FA identisch, scope 'single').
     */
    public static function aktuellIdOhneAbfrage(): ?int
    {
        if (Context::hasHidden(self::SCHLUESSEL)) {
            $id = Context::getHidden(self::SCHLUESSEL);

            return $id === null ? null : (int) $id;
        }
        if (TeamContext::hasOverride()) {
            return (int) TeamContext::getOverrideId();
        }
        $u = Auth::hasUser() ? Auth::user() : null;   // nie selbst eine Nutzer-Abfrage auslösen

        return $u !== null && isset($u->current_team_id) ? (int) $u->current_team_id : null;
    }

    /** Ist ein Team ausdrücklich gesetzt (imTeam oder Job-Payload)? */
    public static function ausdruecklich(): bool
    {
        return Context::hasHidden(self::SCHLUESSEL);
    }

    private function ermittle(): ?Team
    {
        if (Context::hasHidden(self::SCHLUESSEL)) {
            $id = Context::getHidden(self::SCHLUESSEL);

            return $id === null ? null : $this->team((int) $id);
        }
        if (TeamContext::hasOverride()) {
            return TeamContext::getOverrideTeam();
        }
        $user = Auth::user();
        if ($user === null || ! method_exists($user, 'currentTeam')) {
            return null;
        }
        $uid = (int) $user->getAuthIdentifier();
        if (! array_key_exists($uid, $this->uiTeam)) {
            $team = $user->currentTeam;
            $this->uiTeam[$uid] = $team?->id !== null ? (int) $team->id : null;
            if ($team instanceof Team) {
                $this->teams[(int) $team->id] = $team;
            }
        }

        return $this->uiTeam[$uid] === null ? null : $this->team($this->uiTeam[$uid]);
    }

    private function team(int $id): ?Team
    {
        if (! array_key_exists($id, $this->teams)) {
            $this->teams[$id] = Team::find($id);
        }

        return $this->teams[$id];
    }
}
