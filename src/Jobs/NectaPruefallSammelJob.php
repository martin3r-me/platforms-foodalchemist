<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\FaRolle;
use Platform\FoodAlchemist\Services\Necta\NectaPruefallService;

/**
 * Sammel-Entscheidung über viele Necta-Prüffälle (Kurator 19.10.): in Teilmengen von {@see NectaPruefallService::SAMMEL_TEIL}, damit
 * io weder CLI- noch FPM-Speicher sprengt. Fortschritt und Fertig-Meldung: NectaPruefallService::sammelStand($lauf).
 * Sync-Queue-Driver (Sandbox/Tests) ⇒ inline.
 */
class NectaPruefallSammelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, \Platform\FoodAlchemist\Support\MitKiVerursacher;

    public int $timeout = 3600;

    public int $tries = 1;

    /** @param list<int> $ids */
    public function __construct(public int $teamId, public ?int $userId, public string $aktion, public array $ids, public string $lauf, public string $rolle)
    {
    }

    public function handle(NectaPruefallService $svc): void
    {
        $team = Team::find($this->teamId);
        if ($team === null) {
            return;
        }
        $teile = array_chunk($this->ids, NectaPruefallService::SAMMEL_TEIL);
        foreach ($teile as $i => $teil) {
            $svc->sammelTeil($team, $teil, $this->aktion, $this->userId, FaRolle::from($this->rolle), $this->lauf, $i === count($teile) - 1);
        }
    }

    /** Abbruch (z. B. Recht entzogen, DB-Fehler): Stand als fertig mit Grund, damit die Oberfläche nicht ewig wartet. */
    public function failed(\Throwable $e): void
    {
        NectaPruefallService::sammelAbbruch($this->lauf, $e->getMessage());
    }
}
