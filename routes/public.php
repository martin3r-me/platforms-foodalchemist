<?php

use Illuminate\Support\Facades\Route;
use Platform\Core\Http\Middleware\NoCacheHeaders;
use Platform\FoodAlchemist\Http\Controllers\PresentationController;

// Spec 43 — Öffentliches digitales Kundenbuch (Präsentation) ohne Login. Token-aufgelöst,
// rendert NUR aus dem eingefrorenen Snapshot. NoCacheHeaders NUR hier (die fa-assets-Route
// unten braucht ihren immutable-Cache). Typen: foodbook, speisekarte, speiseplan, angebot.
// Web-App-Manifest (PWA „Zum Startbildschirm hinzufügen") — additiv zur Präsentation,
// spezifischer 3-Segment-Pfad, daher VOR der 2-Segment-show-Route registriert.
Route::get('/p/{type}/{token}/app.webmanifest', [PresentationController::class, 'manifest'])
    ->whereIn('type', ['foodbook', 'speisekarte', 'speiseplan', 'angebot'])
    ->where('token', '[A-Za-z0-9-]+')
    ->middleware(NoCacheHeaders::class)
    ->name('foodalchemist.presentation.manifest');

// Share-Card fürs Link-Vorschaubild (Open Graph 1200×630) — additiv, spezifischer 3-Segment-Pfad.
Route::get('/p/{type}/{token}/share-card.png', [PresentationController::class, 'shareCard'])
    ->whereIn('type', ['foodbook', 'speisekarte', 'speiseplan', 'angebot'])
    ->where('token', '[A-Za-z0-9-]+')
    ->middleware(NoCacheHeaders::class)
    ->name('foodalchemist.presentation.share_card');

// PWA-/Homescreen-Icon (Logo auf Marken-Quadrat) — additiv, spezifischer 3-Segment-Pfad.
Route::get('/p/{type}/{token}/app-icon.png', [PresentationController::class, 'appIcon'])
    ->whereIn('type', ['foodbook', 'speisekarte', 'speiseplan', 'angebot'])
    ->where('token', '[A-Za-z0-9-]+')
    ->middleware(NoCacheHeaders::class)
    ->name('foodalchemist.presentation.app_icon');

// Spec 86 — Handbuch (Standards zum Nachschlagen): eigener Token, eigener Link neben dem Kundenlink.
// Routenname enthält das Segment „presentation" → bereichsfrei und im gesperrten Team erreichbar
// (FaBereiche::ROUTEN / TeamSperre::ROUTEN_AUSNAHME), genau wie die Präsentation.
Route::get('/p/handbuch-stil.css', [\Platform\FoodAlchemist\Http\Controllers\HandbuchController::class, 'stil'])
    ->name('foodalchemist.presentation.handbuch_css');

Route::get('/p/handbuch/{token}', [\Platform\FoodAlchemist\Http\Controllers\HandbuchController::class, 'show'])
    ->where('token', '[A-Za-z0-9-]+')
    ->middleware(NoCacheHeaders::class)
    ->name('foodalchemist.presentation.handbuch');

// Lieferanten-Portal (Stufe 2): persönlicher Link je Lieferant, ohne Login. Eigenes Muster /l/…; Routenname mit „presentation“ ⇒ bereichsfrei (kein Login-Bereich).
// throttle: wer Tokens rät, wird gebremst. Ungültig/abgelaufen/widerrufen/gesperrt antworten gleich (410).
Route::get('/l/portal.css', [\Platform\FoodAlchemist\Http\Controllers\LieferantenPortalController::class, 'stil'])
    ->name('foodalchemist.presentation.lieferanten_portal_css');

Route::get('/l/{token}', [\Platform\FoodAlchemist\Http\Controllers\LieferantenPortalController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{32,64}')
    ->middleware(['throttle:30,1', NoCacheHeaders::class])
    ->name('foodalchemist.presentation.lieferanten_portal');

Route::get('/p/{type}/{token}', [PresentationController::class, 'show'])
    ->whereIn('type', ['foodbook', 'speisekarte', 'speiseplan', 'angebot'])
    ->where('token', '[A-Za-z0-9-]+')
    ->middleware(NoCacheHeaders::class)
    ->name('foodalchemist.presentation.show');

// Serve FoodAlchemist frontend bundles (JS) with immutable caching — analog
// zu platform-core's _platform/assets/{file} (siehe CoreServiceProvider).
Route::get('_platform/fa-assets/{file}', function (string $file) {
    $distDir = realpath(__DIR__ . '/../resources/dist');
    $filePath = realpath($distDir . '/' . $file);

    if (!$filePath || !str_starts_with($filePath, $distDir) || !file_exists($filePath)) {
        abort(404);
    }

    $mimeTypes = ['js' => 'application/javascript', 'css' => 'text/css'];
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    $mime = $mimeTypes[$ext] ?? 'application/octet-stream';

    return response()->file($filePath, [
        'Content-Type' => $mime,
        'Cache-Control' => 'public, max-age=31536000, immutable',
    ]);
})->where('file', '[a-zA-Z0-9._-]+\.(js|css)')->name('foodalchemist.platform-asset');

// #1003 PWA Stufe 1: Food Alchemist als App (Manifest + Icons). Ohne Login, Browser laden das Manifest ohne Cookies.
Route::get('_platform/fa-app/manifest.webmanifest', [\Platform\FoodAlchemist\Http\Controllers\HandyAppController::class, 'manifest'])
    ->name('foodalchemist.handy_app.manifest');
Route::get('_platform/fa-app/{name}.png', [\Platform\FoodAlchemist\Http\Controllers\HandyAppController::class, 'icon'])
    ->where('name', '[a-z0-9-]+')
    ->name('foodalchemist.handy_app.icon');

// Bearer-Download nur mit zeitlich begrenzter Signatur. Die Bytes wurden bereits
// über den Teamkontext des MCP-Aufrufs autorisiert; kein Zugriff auf lebende IDs.
Route::get('/_platform/fa-recipe-pdf/{token}', \Platform\FoodAlchemist\Http\Controllers\RecipePdfDownloadController::class)
    ->where('token', '[a-f0-9]{64}')
    ->middleware('signed')
    ->name('foodalchemist.recipes.pdf_download');
