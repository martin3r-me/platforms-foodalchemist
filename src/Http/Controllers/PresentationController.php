<?php

namespace Platform\FoodAlchemist\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Platform\Core\Http\Controllers\Controller;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Services\PresentationShareCardService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spec 43 — Öffentlicher Renderer des digitalen Kundenbuchs. KEIN Login, KEIN Team-
 * Kontext: die Ausgabe wird ausschließlich per Token aufgelöst und NUR aus dem
 * eingefrorenen Snapshot gerendert (kein Live-/team-gescopter Query). Ungültig,
 * abgelaufen oder zurückgezogen → 404.
 */
class PresentationController extends Controller
{
    public function show(string $type, string $token, PresentationService $svc): View
    {
        $snapshot = $svc->resolveByToken($type, $token);
        abort_if($snapshot === null, 404);

        // Additiv (mobil): kanonische URL + Web-App-Manifest an die View — nur der öffentliche
        // Token-Pfad kennt sie; die interne Vorschau (doPreview) lässt sie leer (kein Install/Unfurl).
        return view('foodalchemist::presentation.show', [
            'snapshot' => $snapshot,
            'publicUrl' => route('foodalchemist.presentation.show', ['type' => $type, 'token' => $token]),
            'manifestUrl' => route('foodalchemist.presentation.manifest', ['type' => $type, 'token' => $token]),
            'shareCardUrl' => route('foodalchemist.presentation.share_card', ['type' => $type, 'token' => $token]),
        ]);
    }

    /**
     * Share-Card fürs Link-Vorschaubild (Open Graph): 1200×630-PNG aus dem Snapshot
     * (Cover + Verlauf + Kunde/Jahr + Titel + Logo). Rein additiv; kein Login/Team.
     * Fällt bei Render-Fehler auf das rohe Cover-Bild zurück, damit die Vorschau nie leer ist.
     */
    public function shareCard(string $type, string $token, PresentationShareCardService $cards): Response
    {
        $png = $cards->render($type, $token);
        if ($png !== null) {
            return response($png, 200, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }
        $fallback = $cards->fallbackImageUrl($type, $token);
        if ($fallback !== null) {
            return redirect()->away($fallback);
        }
        abort(404);
    }

    /**
     * Web-App-Manifest der öffentlichen Präsentation (PWA „Zum Startbildschirm hinzufügen").
     * Aus demselben eingefrorenen Snapshot wie show(); rein additiv — ändert das Rendering nicht.
     */
    public function manifest(string $type, string $token, PresentationService $svc): JsonResponse
    {
        $snapshot = $svc->resolveByToken($type, $token);
        abort_if($snapshot === null, 404);

        $pal = $snapshot['resolved_design']['tokens']['palette'] ?? [];
        $primary = $this->sanitizeColor($pal['primary'] ?? null, '#6d28d9');
        $bg = $this->sanitizeColor($pal['bg'] ?? null, '#fbfaf8');
        $title = trim((string) ($snapshot['title'] ?? 'Kundenbuch')) ?: 'Kundenbuch';
        $start = route('foodalchemist.presentation.show', ['type' => $type, 'token' => $token]);
        $desc = $snapshot['subtitle'] ?? ($snapshot['meta']['customer'] ?? null);

        $manifest = array_filter([
            'id' => $start,
            'name' => $title,
            'short_name' => Str::limit($title, 18, ''),
            'description' => $desc,
            'start_url' => $start,
            'scope' => $start,
            'display' => 'standalone',
            'orientation' => 'portrait',
            'theme_color' => $primary,
            'background_color' => $bg,
            'lang' => str_replace('_', '-', app()->getLocale()),
            'icons' => [[
                'src' => $this->svgIcon($title, $primary),
                'sizes' => 'any',
                'type' => 'image/svg+xml',
                'purpose' => 'any maskable',
            ]],
        ], fn ($v) => $v !== null && $v !== '');

        return response()->json($manifest, 200, [
            'Content-Type' => 'application/manifest+json',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Nur einfache Farb-Literale (Hex / rgb[a]) durchlassen — sonst Fallback (kein CSS-Injection ins SVG/JSON). */
    private function sanitizeColor(?string $value, string $fallback): string
    {
        $value = trim((string) $value);

        return preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([0-9.,\s%]+\))$/', $value) === 1 ? $value : $fallback;
    }

    /** Data-URI-App-Icon: markenfarbenes Kachel-Quadrat mit dem Anfangsbuchstaben (kein Binär-Upload nötig). */
    private function svgIcon(string $title, string $primary): string
    {
        $letter = mb_strtoupper(mb_substr($title, 0, 1, 'UTF-8'));
        $letter = htmlspecialchars($letter, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512">'
            . '<rect width="512" height="512" rx="112" fill="' . $primary . '"/>'
            . '<text x="256" y="340" font-family="Georgia,serif" font-size="300" font-weight="700" '
            . 'text-anchor="middle" fill="#ffffff">' . $letter . '</text></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Interne LIVE-Vorschau (auth + team-gescopt): rendert dieselben Templates aus den
     * aktuellen Daten (nicht aus dem Snapshot) — „Vorschau == Veröffentlicht". Optionaler
     * ?design=-Override probiert ein anderes Design, ohne es zu speichern.
     */
    public function preview(int $id, PresentationService $svc): View
    {
        return $this->doPreview(PresentationService::TYPE_FOODBOOK, $id, $svc);
    }

    public function previewSpeisekarte(int $id, PresentationService $svc): View
    {
        return $this->doPreview(PresentationService::TYPE_SPEISEKARTE, $id, $svc);
    }

    public function previewSpeiseplan(int $id, PresentationService $svc): View
    {
        return $this->doPreview(PresentationService::TYPE_SPEISEPLAN, $id, $svc);
    }

    private function doPreview(string $type, int $id, PresentationService $svc): View
    {
        $team = auth()->user()?->currentTeamRelation ?? abort(403, 'Kein Team zugeordnet.');
        $snapshot = $svc->previewData($team, $type, $id, request()->query('design'));

        return view('foodalchemist::presentation.show', ['snapshot' => $snapshot, 'preview' => true]);
    }
}
