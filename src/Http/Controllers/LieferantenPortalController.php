<?php

namespace Platform\FoodAlchemist\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Platform\Core\Http\Controllers\Controller;
use Platform\FoodAlchemist\Models\FoodAlchemistLieferantenLink;
use Platform\FoodAlchemist\Services\LieferantenLinkService;
use Platform\FoodAlchemist\Services\LieferantenUploadService;

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
            'token' => $token, 'meldung' => session('portal_meldung'), 'fehler' => session('portal_fehler'),
            'cssVersion' => substr((string) @md5_file($this->cssPfad()), 0, 8),
        ]));
    }

    /**
     * Datei einreichen (Preisliste oder Stammdaten). Landet als Lauf in der Prüfliste des Teams, nie direkt im Bestand. Der Lieferant sieht nur eine
     * kurze Rückmeldung; Fehlermeldungen kommen aus {@see LieferantenUploadService} und enthalten keine Pfade oder technischen Details.
     */
    public function upload(string $token, Request $request, LieferantenLinkService $svc, LieferantenUploadService $upload): Response|RedirectResponse
    {
        $link = $svc->aufloesen($token);
        if ($link === null) {
            return $this->antwort(response()->view('foodalchemist::lieferanten-portal.ungueltig', [], 410));
        }
        $weiter = redirect()->route('foodalchemist.presentation.lieferanten_portal', ['token' => $token]);
        $datei = $request->file('datei');
        if (! $datei instanceof \Illuminate\Http\UploadedFile) {
            return $weiter->with('portal_fehler', 'Bitte eine Datei auswählen.');
        }
        try {
            $r = $upload->annehmen($link, (string) $request->input('bereich'), $datei, $request);
        } catch (\RuntimeException $e) {
            return $weiter->with('portal_fehler', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return $weiter->with('portal_fehler', 'Die Datei konnte nicht verarbeitet werden. Bitte prüfe sie oder melde dich bei uns.');
        }

        return $weiter->with('portal_meldung', $r['zuordnung_noetig']
            ? 'Danke, die Datei ist angekommen. Wir ordnen die Spalten zu und prüfen sie, bevor etwas übernommen wird.'
            : "Danke, die Datei ist angekommen ({$r['zeilen']} Zeilen). Wir prüfen sie, bevor etwas übernommen wird.");
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
