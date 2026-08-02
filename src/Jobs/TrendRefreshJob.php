<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Trendradar: „Trends jetzt importieren & clustern" aus den Einstellungen.
 *
 * ASYNC, weil beides teuer ist: der Wissens-Import liest den Vault, das Clustern ruft das
 * Modell in Batches (auf demo ~200 Trend-Docs). Im Livewire-Request liefe das ins Timeout —
 * dieselbe Lehre wie {@see QualityRunJob}. Sync-Queue (Sandbox/Tests) ⇒ läuft inline, gleiches
 * Verhalten. Global (kein Team-Kontext): Trend-Docs + Taxonomie sind teamübergreifend.
 *
 * Idempotent: knowledge-import ist Einbahn-Guard, trend-cluster überspringt schon gelabelte
 * Docs (ohne --reklassifizieren). Ein zweiter Lauf kostet nur Zeit.
 */
class TrendRefreshJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Import + Batch-Labeling über ~200 Docs — großzügig, aber unter den Anreicherungs-Läufen. */
    public int $timeout = 1800;

    public int $tries = 1;

    public function handle(): void
    {
        Log::info('TrendRefreshJob: knowledge-import (trend) startet.');
        Artisan::call('foodalchemist:knowledge-import');
        Log::info('TrendRefreshJob: trend-cluster startet.');
        Artisan::call('foodalchemist:trend-cluster');
        Log::info('TrendRefreshJob: fertig.');
    }
}
