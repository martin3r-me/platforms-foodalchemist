<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;

/**
 * Spec 77d · Inhalt einer freigegebenen Ausgabe hat sich geändert (Slot, Block, Zutat, Position …) →
 * die gespeicherten Hüllen aller Empfänger neu rechnen. Einmalig in der Warteschlange (Bulk-Änderungen
 * rechnen nicht hundertfach). Sync-Queue-Driver (Sandbox/Tests) ⇒ läuft inline.
 */
class InhaltsFreigabeHuelleJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor = 120;

    public function handle(InhaltsFreigabeService $svc): void
    {
        $svc->neuBerechnenAlle();
    }
}
