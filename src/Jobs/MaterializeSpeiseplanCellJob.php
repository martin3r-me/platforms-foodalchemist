<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Services\PlanningCascadeService;

/**
 * Zell-Worker der Speiseplan-Voll-Kaskade (P5): erdet EINE leere Zyklus-Zelle zu einem VK-Gericht und
 * trägt es in die Zelle (Datum/Mahlzeit/Linie) ein. Ein Job je Zelle — jede Generierung in ihrer eigenen
 * Queue-Ausführung (kein 502), Rückmeldung an den eigenen Kaskaden-Step. Spiegelt {@see MaterializeConceptIdeaJob}.
 *
 * Die eigentliche Arbeit liegt in {@see PlanningCascadeService::materialisiereSpeiseplanZelle}.
 */
class MaterializeSpeiseplanCellJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public int $teamId,
        public int $userId,
        public int $planId,
        public string $entryDate,
        public string $meal,
        public int $lineId,
        public string $brief,
        public int $cascadeStepId,
        public ?int $planningSessionId = null,
    ) {}

    public function handle(): void
    {
        $team = Team::find($this->teamId);
        if ($team === null) {
            app(PlanningCascadeService::class)->markStepFailed($this->cascadeStepId, 'Team nicht gefunden.');

            return;
        }
        $user = User::find($this->userId);
        if ($user !== null) {
            Auth::login($user);   // Team-Kontext für AiGatewayService
        }

        app(PlanningCascadeService::class)->materialisiereSpeiseplanZelle(
            $team, $this->planId, $this->entryDate, $this->meal, $this->lineId, $this->brief, $this->cascadeStepId, $this->planningSessionId
        );
    }

    /** Job-Tod → Step terminal setzen, sonst hängt der Run auf „running". */
    public function failed(\Throwable $e): void
    {
        app(PlanningCascadeService::class)->markStepFailed($this->cascadeStepId, 'Zell-Kaskade abgebrochen: ' . $e->getMessage());
    }
}
