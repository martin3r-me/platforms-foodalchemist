<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Platform\FoodAlchemist\Services\RezeptFolgeService;

/**
 * Spec 82 §12 B / 4d · Eigene Rezepte eines Mandanten folgen der neuen Abo-Fassung — als Job, wenn die Zahl der betroffenen Rezepte die synchrone Grenze
 * übersteigt (Veröffentlichung blockiert nicht). Einmalig je Mandant (Unique), nach dem Commit der Veröffentlichung. Auf demo nach jedem Deploy
 * `queue:restart`, sonst läuft der Worker mit altem Code. Sync-Queue-Driver (Sandbox/Tests) ⇒ läuft inline.
 */
class RezeptFolgeJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, \Platform\FoodAlchemist\Support\MitKiVerursacher;

    public int $uniqueFor = 300;

    /** @param  list<int>  $familien */
    public function __construct(public int $teamId, public array $familien)
    {
    }

    public function uniqueId(): string
    {
        return 'rezept-folge-'.$this->teamId;
    }

    public function handle(RezeptFolgeService $svc): void
    {
        $svc->folgenFuerTeam($this->teamId, $this->familien, true);
    }
}
