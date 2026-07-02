<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\SignalDetektorService;

/**
 * #378: Signal-Detektor-Lauf — erzeugt/aktualisiert Klasse-B-Signale (Datenqualität
 * GP/LA aktiv; Preis-Anomalie/veraltete Preise/Marge folgen) je Team. Idempotent
 * über dedup_key. Für den Scheduler gedacht (z.B. täglich); Registrierung der
 * Cron-Frequenz ist Host-/Deploy-Sache (Console-Kernel der office.bhg-App).
 */
class SignaleDetektorCommand extends Command
{
    protected $signature = 'foodalchemist:signale-detektor {--team= : nur dieses Team (ID), sonst alle}';

    protected $description = '#378: Detektor-Lauf — erzeugt/aktualisiert Klasse-B-Signale je Team (idempotent).';

    public function handle(SignalDetektorService $det): int
    {
        $teams = $this->option('team')
            ? Team::whereKey((int) $this->option('team'))->get()
            : Team::query()->get();

        $gesamt = 0;
        foreach ($teams as $team) {
            $n = $det->laufen($team);
            $gesamt += $n;
            $this->line("Team {$team->id} ({$team->name}): {$n} Signal(e)");
        }
        $this->info("Fertig — {$gesamt} Signal(e) über {$teams->count()} Team(s).");

        return self::SUCCESS;
    }
}
