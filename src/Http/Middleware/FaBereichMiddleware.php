<?php

namespace Platform\FoodAlchemist\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Support\FaBereiche;
use Platform\FoodAlchemist\Support\TeamSperre;

/**
 * Spec 77b · Seitenaufruf nur, wenn der Bereich der Route für das Team freigeschaltet und für den
 * User nicht eingeschränkt ist. Nicht-FA-Routen und bereichsfreie Routen laufen durch.
 */
class FaBereichMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $routeName = $request->route()?->getName();
        $bereich = FaBereiche::fuerRoute($routeName);
        $user = $request->user();
        // Spec 82 §13 C3: Vollsperre eines beendeten Mandanten — vor jeder Bereichsprüfung, auch für Plattform-Admins
        if ($user instanceof User && ! TeamSperre::routeErlaubt($routeName) && TeamSperre::istGesperrt($user->currentTeamRelation)) {
            abort(403, TeamSperre::MELDUNG);
        }
        // Spec 82 §13 G: Plattform-Admin ohne echte Mitgliedschaft sieht keine Inhalte eines Kunden-Teams (Core lässt den Team-Wechsel ohne Prüfung zu)
        if ($user instanceof User && ! \Platform\FoodAlchemist\Support\InhaltsSperre::routeErlaubt($routeName)
            && \Platform\FoodAlchemist\Support\InhaltsSperre::istGesperrt($user, $user->currentTeamRelation)) {
            abort(403, \Platform\FoodAlchemist\Support\InhaltsSperre::MELDUNG);
        }
        // Spec 84 §4.4 (E3): Seitenaufruf im Support-Zugang → Protokoll
        if ($user instanceof User && ($sf = \Platform\FoodAlchemist\Support\InhaltsSperre::perSupportZugang($user, $user->currentTeamRelation)) !== null) {
            app(\Platform\FoodAlchemist\Services\SupportFreigabeService::class)->protokolliereZugriff($sf, $user, $user->currentTeamRelation, 'seite:'.($routeName ?? $request->path()));
        }
        if ($bereich !== null && $user instanceof User && ($team = $user->currentTeamRelation) !== null
            && ! app(FaRechte::class)->darfBereich($user, $team, $bereich)) {
            abort(403, 'Der Bereich „'.(FaBereiche::KATALOG[$bereich] ?? $bereich).'“ ist für dich bzw. dein Team nicht freigeschaltet.');
        }

        // Spec 84 B3: FA-Seite = Eingang; mit angemeldetem Nutzer ist eine Abfrage ohne Team-Kontext ein Fehler, kein leeres Ergebnis
        return \Platform\FoodAlchemist\Models\Scopes\TeamSicht::imEingang('seite', $user instanceof User, fn () => $next($request));
    }
}
