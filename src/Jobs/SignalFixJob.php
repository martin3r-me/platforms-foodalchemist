<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSignal;
use Platform\FoodAlchemist\Services\SignalFixService;

/**
 * „KI erledigen lassen" (deterministisch) — den betroffenen Satz eines Signals beheben.
 * ASYNC: der Cockpit-Klick blockiert nicht an einer möglichen Masse
 * (demo: 182 GPs / 223 Preis-Anomalien). Sync-Queue-Driver (Sandbox) ⇒ läuft inline.
 *
 * Spec 21 · S3b: `$ids` trägt eine Auswahl (Teil-Bulk aus dem Signal-Panel) durch. Der
 * Job prüft sie NICHT selbst — `SignalFixService::execute` schneidet jede Auswahl gegen
 * `betroffene()`, damit es genau eine Autorisierungs-Stelle gibt.
 *
 * Idempotent genug: `execute` scoped auf `betroffene()` (bei bereits behobenem Signal
 * leer) und schließt es bei count 0. Nur deterministische Pläne; Assist läuft synchron
 * im Component (ein propose()-Call), nicht hier.
 */
class SignalFixJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;   // Bulk-Recompute kann dauern (async)

    /** @param list<int>|null $ids Auswahl (Teil-Bulk) oder null = voller betroffener Satz */
    public function __construct(
        public int $signalId,
        public int $teamId,
        public ?array $ids = null,
    ) {
    }

    public function handle(SignalFixService $svc): void
    {
        $team = Team::find($this->teamId);
        if ($team === null) {
            return;
        }
        $sig = FoodAlchemistSignal::visibleToTeam($team)->find($this->signalId);
        if ($sig === null) {
            return;
        }

        try {
            $svc->execute($team, $sig, $this->ids);
        } catch (\RuntimeException $e) {
            // Kein automatischer Fix für dieses Signal (z. B. Plan-Änderung) → No-op,
            // aber ab 22·H3b nicht mehr stumm: ein Signal, dessen Plan sich unter dem
            // Klick geändert hat, sieht sonst aus wie ein Fix, der nichts fand.
            Log::warning('[FA/H3b] SignalFixJob: kein automatischer Fix', [
                'signal_id' => $this->signalId, 'team_id' => $this->teamId, 'fehler' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 22·H3b · V-054 (Queue-Pfad): dieser Job führt keine Lauf-Zeile und hat kein Feld,
     * das er auf „gescheitert" setzen könnte — das Signal selbst bleibt korrekt offen.
     * Was fehlte, ist die **Spur**: stirbt er am Timeout (600 s, Bulk-Recompute), sieht
     * ein Nutzer, der „diese 12 fixen" gedrückt hat, danach 12 offene Objekte und kann
     * „nicht auflösbar" nicht von „abgestürzt" unterscheiden. Eine Log-Zeile ist hier die
     * ganze mögliche Ehrlichkeit — sie ist mehr als die vorherige Stille.
     */
    public function failed(?\Throwable $e): void
    {
        Log::warning('[FA/H3b] SignalFixJob abgebrochen', [
            'signal_id' => $this->signalId, 'team_id' => $this->teamId,
            'auswahl' => $this->ids === null ? 'alle' : count($this->ids),
            'fehler' => $e?->getMessage(),
        ]);
    }
}
