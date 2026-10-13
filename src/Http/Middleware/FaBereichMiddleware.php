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
        if ($bereich !== null && $user instanceof User && ($team = $user->currentTeamRelation) !== null
            && ! app(FaRechte::class)->darfBereich($user, $team, $bereich)) {
            abort(403, 'Der Bereich „'.(FaBereiche::KATALOG[$bereich] ?? $bereich).'“ ist für dich bzw. dein Team nicht freigeschaltet.');
        }

        return $next($request);
    }
}
