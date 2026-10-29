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
class SensorikNachholenJob implements ShouldBeUnique, ShouldQueue, \Platform\FoodAlchemist\Services\Ai\KiExternWiederholbar
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, \Platform\FoodAlchemist\Support\MitKiVerursacher;   // Spec 84 A-3: Job läuft im Auftragsteam

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 900;

    public function __construct(
        public int $teamId,
        public int $userId,
        public int $recipeId,
        public bool $extern = false,   // #1002 P2c: ausgelöst aus einem externen Lauf → KI-Auftrag statt Plattform-Provider
        public int $versuch = 0,
    ) {}

    // ── #1002 P2c · KiExternWiederholbar ──
    public function externAktiv(): bool
    {
        return $this->extern;
    }

    public function externSchluessel(): string
    {
        return "sensorik-nachholen:{$this->teamId}:{$this->recipeId}";
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
        return ['team_id' => $this->teamId, 'user_id' => $this->userId, 'recipe_id' => $this->recipeId];
    }

    public static function fromExternNutzlast(array $n, int $versuch): static
    {
        return new static((int) $n['team_id'], (int) $n['user_id'], (int) $n['recipe_id'], true, $versuch);
    }

    public function uniqueId(): string
    {
        return 'sensorik-nachholen-'.$this->recipeId;
    }

    public function handle(SensorikService $sensorik): void
    {
        app(\Platform\FoodAlchemist\Services\Ai\KiExternLauf::class)->fuehreAus($this, fn () => $this->arbeite($sensorik));
    }

    /** Der Job-Rumpf — im Plattformweg unverändert, im externen Weg in der Sammel-Transaktion (KiExternLauf). */
    private function arbeite(SensorikService $sensorik): void
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
        } catch (\Platform\FoodAlchemist\Exceptions\KiAuftragAusstehendException) {
            // wartet auf die externe Antwort — kein Fehler, ANTWORT startet den Lauf neu
        } catch (\Throwable $e) {
            Log::warning("Sensorik nachholen für Rezept {$this->recipeId} fehlgeschlagen: {$e->getMessage()}");
        }
    }
}
