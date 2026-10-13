<?php

namespace Platform\FoodAlchemist\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Models\FoodAlchemistMandantExport;
use Platform\FoodAlchemist\Services\MandantExportService;

/**
 * Spec 82 §13 C3 — Download des Mandanten-Exports. Hängt an KEINEM Bereich und bleibt auch im gesperrten Team erreichbar
 * (Ausnahme in {@see \Platform\FoodAlchemist\Support\TeamSperre}). Laden darf nur, wer wirklich Inhaber/Admin im Mandanten ist
 * (oder der Master bei kuratierten Mandanten); ein reiner Plattform-Admin bekommt 403 (§13 G).
 */
class MandantExportDownloadController
{
    public function __invoke(Request $request, int $export, MandantExportService $svc)
    {
        $eintrag = FoodAlchemistMandantExport::findOrFail($export);
        try {
            $pfad = $svc->fuerDownload($eintrag, $request->user());
        } catch (FaRechtFehltException $e) {
            abort(403, $e->getMessage());
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }

        return Storage::disk(MandantExportService::disk())->download($pfad, 'foodalchemist-export-'.$eintrag->team_id.'-'.($eintrag->fertig_at?->format('Ymd') ?? 'x').'.zip', [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
