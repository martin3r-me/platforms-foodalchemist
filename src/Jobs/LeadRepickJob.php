<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\LeadLaService;

/**
 * „Leads neu wählen" übernehmen (Settings/Einkauf, MCP lead_la.REPICK). ASYNC, weil danach
 * alle nutzenden Rezepte neu gerechnet werden. Sync-Queue-Driver (Sandbox/Tests) ⇒ inline.
 * Die Auswahl wird in LeadLaService::repickAnwenden() gegen eine frische Vorschau geschnitten.
 */
class LeadRepickJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    /** @param list<int> $gpIds */
    public function __construct(public int $teamId, public array $gpIds)
    {
    }

    public function handle(LeadLaService $leads): void
    {
        $team = Team::find($this->teamId);
        if ($team !== null) {
            $leads->repickAnwenden($team, $this->gpIds);
        }
    }
}
