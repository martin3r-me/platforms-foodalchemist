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
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Services\GpAromaService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Support\GpAromaNachholen;

/**
 * Holt Kern-Anker und Geschmacksprofil eines neuen GP per KI nach ({@see GpAromaNachholen}). Derselbe Weg wie „Alles anreichern":
 * GP-Lauf als Audit-Spur, Vorschlag, sofortige Übernahme (Override-First). Je GP eindeutig; ein Fehler wird geloggt, nicht
 * wiederholt — kein Retry-Sturm auf dem Provider, die Knöpfe im Aroma-Reiter bleiben als Weg.
 */
class GpAromaNachholenJob implements ShouldBeUnique, ShouldQueue, \Platform\FoodAlchemist\Services\Ai\KiExternWiederholbar
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, \Platform\FoodAlchemist\Support\MitKiVerursacher;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 900;

    public function __construct(
        public int $teamId,
        public int $userId,
        public int $gpId,
        public bool $extern = false,   // #1002 LA-Wege: ausgelöst von einem externen MCP-Aufruf → KI-Auftrag statt Plattform-Provider
        public int $versuch = 0,
    ) {}

    // ── #1002 · KiExternWiederholbar ──
    public function externAktiv(): bool
    {
        return $this->extern;
    }

    public function externSchluessel(): string
    {
        return "gp-aroma-nachholen:{$this->teamId}:{$this->gpId}";
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
        return 'gp-aroma-nachholen-'.$this->gpId;
    }

    public function handle(BulkEnrichService $bulk, GpAromaService $aroma): void
    {
        app(\Platform\FoodAlchemist\Services\Ai\KiExternLauf::class)->fuehreAus($this, fn () => $this->arbeite($bulk, $aroma));
    }

    /** Der Job-Rumpf — im Plattformweg unverändert, im externen Weg in der Sammel-Transaktion (KiExternLauf). */
    private function arbeite(BulkEnrichService $bulk, GpAromaService $aroma): void
    {
        $team = Team::find($this->teamId);
        $user = User::find($this->userId);
        $gp = FoodAlchemistGp::find($this->gpId);
        if ($team === null || $user === null || ! $aroma->anreicherbar($gp) || ! app(TeamSettingsService::class)->kiAktiv($team)) {
            return;
        }
        $aroma->geschwisterNachziehen([$this->gpId]);                // inzwischen könnten Geschwister da sein
        $schritte = GpAromaNachholen::schritte($this->gpId);
        if ($schritte === []) {
            return;
        }
        Auth::login($user);   // Team-Kontext für Call-Log, Kill-Switch und Budget
        try {
            $r = $bulk->nachziehenGp($team, [$this->gpId], $schritte, 'gp_anlage');
            Log::info("GP-Aroma nachgeholt für GP {$this->gpId}: ".json_encode($r));
        } catch (\Throwable $e) {
            Log::warning("GP-Aroma nachholen für GP {$this->gpId} fehlgeschlagen: {$e->getMessage()}");
        }
    }
}
