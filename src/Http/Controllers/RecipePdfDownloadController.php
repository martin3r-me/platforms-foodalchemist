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

        return response($export['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $export['filename'] . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
