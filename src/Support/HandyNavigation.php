<?php

namespace Platform\FoodAlchemist\Support;

use Platform\FoodAlchemist\Services\ActiveOutletContext;
use Platform\FoodAlchemist\Services\SignalService;

/**
 * #1003 Untere Leiste der Handy-Oberfläche (Daumenzone) und Blatt „Mehr“.
 *
 * Quelle bleibt config('foodalchemist.sidebar') mit derselben Sichtbarkeits-Regel wie die Desktop-Navigation
 * (FaBereiche::sichtbareNavigation): was das Team bzw. die Person nicht sehen darf, erscheint auch am Handy nicht.
 * Hier steht nur, WELCHE Seiten am Handy sinnvoll sind (Lesen/Planung) und in welcher Reihenfolge.
 */
final class HandyNavigation
{
    /** Reiter der unteren Leiste (Suche ist immer da, „Mehr“ öffnet das Blatt). */
    public const REITER = [
        'foodalchemist.recipes.index' => 'Rezepte',
        'foodalchemist.planung.index' => 'Planung',
        'foodalchemist.review' => 'Signale',
    ];

    /** Blatt „Mehr“: Dashboard ganz oben, dann die Lese-Bereiche (docs/PLANUNG/_Handy_Ansichten_1003.md). */
    public const MEHR = [
        'foodalchemist.dashboard',
        'foodalchemist.recipes.index',
        'foodalchemist.verkauf.index',
        'foodalchemist.gps.index',
        'foodalchemist.suppliers.index',
        'foodalchemist.produktion.index',
        'foodalchemist.orders.index',
        'foodalchemist.concepter.index',
        'foodalchemist.formate.index',
        'foodalchemist.projekte.index',
        'foodalchemist.speiseplan.index',
        'foodalchemist.speisekarte.index',
        'foodalchemist.foodbooks.index',
    ];

    /**
     * @return array{reiter: list<array{route:string,label:string,icon:string}>, mehr: list<array{route:string,label:string,icon:string}>}
     */
    public static function fuer(mixed $user): array
    {
        $sichtbar = [];
        foreach (FaBereiche::sichtbareNavigation(config('foodalchemist.sidebar', []), $user) as $gruppe) {
            foreach ($gruppe['items'] ?? [] as $item) {
                $sichtbar[$item['route']] = ['route' => $item['route'], 'label' => $item['label'], 'icon' => $item['icon'] ?? 'heroicon-o-cube'];
            }
        }

        $reiter = [];
        foreach (self::REITER as $route => $label) {
            if (isset($sichtbar[$route])) {
                $reiter[] = ['route' => $route, 'label' => $label, 'icon' => $sichtbar[$route]['icon']];
            }
        }

        $mehr = [];
        foreach (self::MEHR as $route) {
            if (isset($sichtbar[$route])) {
                $mehr[] = $sichtbar[$route];
            }
        }

        return ['reiter' => $reiter, 'mehr' => $mehr];
    }

    /** Offene Signale wie auf „Zu prüfen“ (aktiver Standort, nur seine Spur). Ein COUNT; null ohne Team. */
    public static function offeneSignale(mixed $user): ?int
    {
        $team = $user?->currentTeamRelation;
        if ($team === null) {
            return null;
        }

        try {
            return app(SignalService::class)->offeneCount($team, app(ActiveOutletContext::class)->current($team), nurLane: true);
        } catch (\Throwable) {
            return null;
        }
    }
}
