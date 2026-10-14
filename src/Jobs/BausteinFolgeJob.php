<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Platform\FoodAlchemist\Services\BausteinFolgeService;

/**
 * Spec 82 Abo Konzepte/Pakete/Formate PR 5 · Verweise eines Mandanten folgen der neuen Konzept-/Paket-Fassung — als Job, wenn die Zahl der betroffenen
 * Verweise die synchrone Grenze übersteigt (Veröffentlichung blockiert nicht). Einmalig je Mandant und Art (Unique), nach dem Commit der Veröffentlichung.
 * Auf demo nach jedem Deploy `queue:restart`. Sync-Queue-Driver (Sandbox/Tests) ⇒ läuft inline.
 */
class BausteinFolgeJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor = 300;

    /** @param  list<int>  $familien */
    public function __construct(public int $teamId, public string $art, public array $familien)
    {
    }

    public function uniqueId(): string
    {
        return 'baustein-folge-'.$this->art.'-'.$this->teamId;
    }

    public function handle(BausteinFolgeService $svc): void
    {
        $svc->folgenFuerTeam($this->teamId, $this->art, $this->familien, true);
    }
}
