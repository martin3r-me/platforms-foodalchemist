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

        // #1003: inline statt attachment — am Handy öffnet der Link im PDF-Betrachter (teilen, sichern), statt still
        // in „Downloads“ zu landen. Dateiname bleibt (beim Sichern), Umlaute RFC-6266-kodiert mit ASCII-Ersatz.
        $name = str_replace(['/', '\\'], '_', (string) $export['filename']);
        $ersatz = trim((string) preg_replace('/[^\x20-\x7E]|["\\\\%\/]/', '_', \Illuminate\Support\Str::ascii($name))) ?: 'Rezept.pdf';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => \Symfony\Component\HttpFoundation\HeaderUtils::makeDisposition('inline', $name, $ersatz),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
