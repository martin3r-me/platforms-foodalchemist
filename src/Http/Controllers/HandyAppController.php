<?php

namespace Platform\FoodAlchemist\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * #1003 PWA Stufe 1: Food Alchemist als App auf dem Startbildschirm (Manifest + Icons), ohne Service Worker.
 * Öffentlich, weil Browser das Manifest ohne Anmelde-Cookies laden. Es verrät nichts außer Name und Icons.
 * Stufe 2 (Offline, Push) → docs/PLANUNG/92_PWA_Offline_Push.md.
 */
class HandyAppController extends Controller
{
    public const ICONS = ['apple-touch-icon', 'icon-192', 'icon-512', 'icon-maskable-512'];

    /** Farbe der Handy-Statusleiste (Manifest und <meta name="theme-color"> im Kopf-Baustein). */
    public const THEME_FARBE = '#0d1424';

    public function manifest(): JsonResponse
    {
        $icon = fn (string $name) => route('foodalchemist.handy_app.icon', ['name' => $name]);
        // Startseite = Dashboard; Geltungsbereich = der FA-Pfad (Modul-Präfix, je nach Host z. B. /foodalchemist)
        $start = rtrim(route('foodalchemist.dashboard', [], false), '/') ?: '/';

        return response()->json([
            'name' => 'Food Alchemist',
            'short_name' => 'Food Alchemist',
            'id' => $start,
            'start_url' => $start.'?quelle=app',
            'scope' => $start,
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#f3f5f8',
            'theme_color' => self::THEME_FARBE,
            'lang' => 'de',
            'icons' => [
                ['src' => $icon('icon-192'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icon('icon-512'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $icon('icon-maskable-512'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=86400']);
    }

    public function icon(string $name): BinaryFileResponse
    {
        abort_unless(in_array($name, self::ICONS, true), 404);

        return response()->file(dirname(__DIR__, 3).'/resources/brand/app/'.$name.'.png', [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }
}
