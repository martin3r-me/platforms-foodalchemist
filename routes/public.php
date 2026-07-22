<?php

use Illuminate\Support\Facades\Route;

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
