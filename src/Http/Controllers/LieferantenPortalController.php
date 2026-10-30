<?php

namespace Platform\FoodAlchemist\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Platform\Core\Http\Controllers\Controller;
use Platform\FoodAlchemist\Models\FoodAlchemistLieferantenLink;
use Platform\FoodAlchemist\Services\LieferantenLinkService;

/**
 * Lieferanten-Portal (Stufe 2): Seite OHNE Login, per Token aufgelöst. Stufe 2a zeigt nur den gültigen Zugang (Lieferant, Gültigkeit, freigegebene Bereiche);
 * die Aktionen (Upload, Formular) folgen mit 2b/2c. Ungültig, abgelaufen, widerrufen oder Team gesperrt ⇒ IMMER dieselbe 410-Seite. Das Portal ist
 * kein Lese-Bereich der Handy-Ansicht (#1003): der Lieferant muss dort schreiben können.
 */
class LieferantenPortalController extends Controller
{
    public function show(string $token, Request $request, LieferantenLinkService $svc): Response
    {
        $link = $svc->aufloesen($token);
        if ($link === null) {
            return $this->antwort(response()->view('foodalchemist::lieferanten-portal.ungueltig', [], 410));
        }
        $svc->benutzt($link, $request);

        return $this->antwort(response()->view('foodalchemist::lieferanten-portal.show', [
            'link' => $link, 'lieferant' => $link->supplier, 'bereiche' => collect($link->bereiche)->map(fn ($b) => FoodAlchemistLieferantenLink::BEREICHE[$b] ?? $b)->all(),
            'cssVersion' => substr((string) @md5_file($this->cssPfad()), 0, 8),
        ]));
    }

    /** Stylesheet des Portals (Farben als CSS-Variablen, kein Inline-Style in der View). Öffentlich. */
    public function stil(): \Symfony\Component\HttpFoundation\Response
    {
        $pfad = $this->cssPfad();
        abort_unless(is_file($pfad), 404);

        return response()->file($pfad, ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    private function antwort(Response $r): Response
    {
        return $r->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    private function cssPfad(): string
    {
        return dirname(__DIR__, 3) . '/resources/css/lieferanten-portal.css';
    }
}
