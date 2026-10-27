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
class GpAromaNachholenJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, \Platform\FoodAlchemist\Support\MitKiVerursacher;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 900;

    public function __construct(public int $teamId, public int $userId, public int $gpId) {}

    public function uniqueId(): string
    {
        return 'gp-aroma-nachholen-'.$this->gpId;
    }

    public function handle(BulkEnrichService $bulk, GpAromaService $aroma): void
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
