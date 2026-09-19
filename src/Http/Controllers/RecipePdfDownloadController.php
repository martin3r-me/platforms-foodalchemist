<?php

namespace Platform\FoodAlchemist\Http\Controllers;

use Illuminate\Support\Facades\Cache;

/** Signierter, zehn Minuten gültiger Zugriff auf einen beim MCP-Aufruf autorisierten Snapshot. */
class RecipePdfDownloadController
{
    public function __invoke(string $token)
    {
        $export = Cache::get('foodalchemist:mcp-recipe-pdf:' . $token);
        abort_unless(is_array($export), 404);

        // Legacy-Snapshots aus binärfähigen Cache-Stores bleiben bis zum TTL-Ablauf lesbar.
        $bytes = isset($export['content_base64'])
            ? base64_decode($export['content_base64'], true)
            : ($export['bytes'] ?? false);
        abort_unless(is_string($bytes) && str_starts_with($bytes, '%PDF-'), 404);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $export['filename'] . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
