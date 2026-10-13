<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\FoodAlchemist\Models\FoodAlchemistMandantExport;
use Platform\FoodAlchemist\Services\MandantExportService;

/**
 * Spec 82 §13 C3 — baut das Export-ZIP eines Mandanten. ASYNC (ein Mandant hat schnell sechsstellige Zeilen);
 * Sync-Queue-Driver (Sandbox/Tests) ⇒ läuft inline. Läuft bewusst OHNE Team-/Sperr-Prüfung: der Export entsteht gerade
 * dann, wenn das Team gesperrt wird.
 */
class MandantExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public FoodAlchemistMandantExport $export)
    {
    }

    public function handle(MandantExportService $service): void
    {
        $service->baue($this->export);
    }

    public function failed(\Throwable $e): void
    {
        $this->export->update(['status' => FoodAlchemistMandantExport::STATUS_FEHLER, 'fehler' => mb_substr($e->getMessage(), 0, 2000)]);
    }
}
