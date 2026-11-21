<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Services\GpFormService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Support\StueckgewichtNachAnlage;

/**
 * Schätzt die Zähl-Formen (u. a. Stückgewicht) eines gerade aus loser Ware angelegten GP ({@see StueckgewichtNachAnlage}) über
 * {@see GpFormService::estimateKi} (gp.zaehl_einheiten, Herkunft ki, manuelle Formen bleiben) und rechnet danach die Rezepte neu,
 * die das GP schon in Stück verwenden. Fehler werden geloggt, nicht wiederholt.
 */
class StueckgewichtNachAnlageJob implements ShouldBeUnique, ShouldQueue, \Platform\FoodAlchemist\Services\Ai\KiExternWiederholbar
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, \Platform\FoodAlchemist\Support\MitKiVerursacher;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public function __construct(
        public int $teamId,
        public int $userId,
        public int $gpId,
        public bool $extern = false,
        public int $versuch = 0,
    ) {}

    // ── #1002 · KiExternWiederholbar ──
    public function externAktiv(): bool
    {
        return $this->extern;
    }

    public function externSchluessel(): string
    {
        return "stueckgewicht-anlage:{$this->teamId}:{$this->gpId}";
    }

    public function externTeamId(): int
    {
        return $this->teamId;
    }

    public function externVersuch(): int
    {
        return $this->versuch;
    }

    public function externNutzlast(): array
    {
        return ['team_id' => $this->teamId, 'user_id' => $this->userId, 'gp_id' => $this->gpId];
    }

    public static function fromExternNutzlast(array $n, int $versuch): static
    {
        return new static((int) $n['team_id'], (int) $n['user_id'], (int) $n['gp_id'], true, $versuch);
    }

    public function uniqueId(): string
    {
        return 'stueckgewicht-anlage-'.$this->gpId;
    }

    public function handle(GpFormService $formen, RecipeRecomputeService $recompute): void
    {
        app(\Platform\FoodAlchemist\Services\Ai\KiExternLauf::class)->fuehreAus($this, fn () => $this->arbeite($formen, $recompute));
    }

    private function arbeite(GpFormService $formen, RecipeRecomputeService $recompute): void
    {
        $team = Team::find($this->teamId);
        $user = User::find($this->userId);
        if ($team === null || $user === null || ! StueckgewichtNachAnlage::ohneStueckgewicht($this->gpId)) {
            return;                                                         // inzwischen von Hand gepflegt
        }
        Auth::login($user);   // Team-Kontext für Call-Log, Kill-Switch, Budget und das Pflege-Gate der Formen
        try {
            $n = $formen->estimateKi($team, $this->gpId);
        } catch (\Throwable $e) {
            Log::info("Stückgewicht für GP {$this->gpId} nicht geschätzt: {$e->getMessage()}");

            return;
        }
        if ($n === 0) {
            return;
        }
        // Rezepte, die das GP schon nutzen (z. B. „4 Stk“ vor Ende der Schätzung eingetragen), rechnen mit dem neuen Gewicht.
        $rezepte = DB::table('foodalchemist_recipe_ingredients')->where('gp_id', $this->gpId)->whereNull('deleted_at')
            ->distinct()->pluck('recipe_id');
        foreach ($rezepte as $rezeptId) {
            $recompute->recomputeAndPropagate((int) $rezeptId);
        }
    }
}
