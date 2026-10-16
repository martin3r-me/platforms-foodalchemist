<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Services\SensorikService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Support\SensorikNachholen;

/**
 * Holt die KI-Sensorik eines neuen Basisrezepts nach ({@see SensorikNachholen}). Je Rezept eindeutig
 * (schnelles Mehrfach-Speichern = ein Lauf). Ein Fehler wird geloggt, nicht wiederholt — kein
 * Retry-Sturm auf dem KI-Provider; der Knopf „✨ Sensorik" bleibt als Weg.
 */
class SensorikNachholenJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 900;

    public function __construct(public int $teamId, public int $userId, public int $recipeId) {}

    public function uniqueId(): string
    {
        return 'sensorik-nachholen-'.$this->recipeId;
    }

    public function handle(SensorikService $sensorik): void
    {
        $team = Team::find($this->teamId);
        $user = User::find($this->userId);
        if ($team === null || $user === null || ! SensorikNachholen::braucht($this->recipeId)
            || ! app(TeamSettingsService::class)->kiAktiv($team)) {
            return;
        }
        Auth::login($user);   // Team-Kontext für Call-Log, Kill-Switch und Budget
        try {
            $r = $sensorik->bewerteRezept($this->recipeId, false);
            Log::info("Sensorik nachgeholt für Rezept {$this->recipeId}: ".($r['status'] ?? '?'));
        } catch (\Throwable $e) {
            Log::warning("Sensorik nachholen für Rezept {$this->recipeId} fehlgeschlagen: {$e->getMessage()}");
        }
    }
}
