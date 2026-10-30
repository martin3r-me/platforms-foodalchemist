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
class MaterializeSpeiseplanCellJob implements ShouldQueue, \Platform\FoodAlchemist\Services\Ai\KiExternWiederholbar
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, \Platform\FoodAlchemist\Support\MitKiVerursacher, \Platform\FoodAlchemist\Services\Ai\KiExternJobTeil;   // Spec 84 A-3: Job läuft im Auftragsteam

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
        public bool $extern = false,   // #1002 Kaskade: aus einem externen Lauf/MCP-Aufruf → KI-Auftrag statt Plattform-Provider
        public int $versuch = 0,
    ) {
        $this->erbeExtern();
    }

    // ── #1002 Kaskade · KiExternWiederholbar ──
    public function externSchluessel(): string
    {
        return "materialize-cell:{$this->teamId}:{$this->cascadeStepId}";
    }

    public function externNutzlast(): array
    {
        return ['team_id' => $this->teamId, 'user_id' => $this->userId, 'plan_id' => $this->planId, 'entry_date' => $this->entryDate, 'meal' => $this->meal, 'line_id' => $this->lineId, 'brief' => $this->brief, 'cascade_step_id' => $this->cascadeStepId, 'planning_session_id' => $this->planningSessionId];
    }

    public static function fromExternNutzlast(array $n, int $versuch): static
    {
        return new static((int) $n['team_id'], (int) $n['user_id'], (int) $n['plan_id'], (string) $n['entry_date'], (string) $n['meal'], (int) $n['line_id'], (string) $n['brief'], (int) $n['cascade_step_id'], isset($n['planning_session_id']) ? (int) $n['planning_session_id'] : null, true, $versuch);
    }

    public function handle(): void
    {
        app(\Platform\FoodAlchemist\Services\Ai\KiExternLauf::class)->fuehreAus($this, fn () => $this->arbeite());
    }

    /** Der Job-Rumpf — im Plattformweg unverändert, im externen Weg in der Sammel-Transaktion (KiExternLauf). */
    private function arbeite(): void
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
