<?php

namespace Platform\FoodAlchemist\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Support\FaBereiche;

/**
 * Spec 77b · Seitenaufruf nur, wenn der Bereich der Route für das Team freigeschaltet und für den
 * User nicht eingeschränkt ist. Nicht-FA-Routen und bereichsfreie Routen laufen durch.
 */
class FaBereichMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $bereich = FaBereiche::fuerRoute($request->route()?->getName());
        $user = $request->user();
        if ($bereich !== null && $user instanceof User && ($team = $user->currentTeamRelation) !== null
            && ! app(FaRechte::class)->darfBereich($user, $team, $bereich)) {
            abort(403, 'Der Bereich „'.(FaBereiche::KATALOG[$bereich] ?? $bereich).'“ ist für dich bzw. dein Team nicht freigeschaltet.');
        }

        return $next($request);
    }
}
