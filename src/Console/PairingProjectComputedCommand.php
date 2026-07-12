<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\PairingProjectionService;

/**
 * Station 2 — projiziert abgeleitete Molekül-Kanten (pairing_computed) als
 * Graph-Kanten (pairing_anchor_edges), nur in Löcher, kuratiert bleibt unangetastet.
 * Default = Dry-Run (nur Statistik). --apply schreibt. Idempotent, resumefähig.
 *
 *   php artisan foodalchemist:pairing-project-computed              # Dry-Run
 *   php artisan foodalchemist:pairing-project-computed --apply      # schreiben (full)
 *   php artisan foodalchemist:pairing-project-computed --apply --min-confidence=0.75
 *   php artisan foodalchemist:pairing-project-computed --purge      # alle computed-Kanten löschen
 */
class PairingProjectComputedCommand extends Command
{
    protected $signature = 'foodalchemist:pairing-project-computed '
        .'{--apply : Kanten wirklich schreiben (sonst nur Dry-Run-Statistik)} '
        .'{--purge : ALLE projizierten computed-Kanten löschen (Batch-Rollback) und beenden} '
        .'{--min-confidence=0 : nur computed-Paare ab dieser Molekül-Confidence (0 = full)} '
        .'{--weight-factor=0.6 : Gewicht = Faktor × Confidence (Obergrenze < kuratiert)} '
        .'{--team=1 : team_id der neuen Kanten (Default 1 = konsistent mit Bestand)}';

    protected $description = 'Station 2: computed Molekül-Kanten in den Anker-Graphen projizieren (holes-only, gradiert).';

    public function handle(PairingProjectionService $svc): int
    {
        if ($this->option('purge')) {
            $n = $svc->purgeComputed();
            $this->warn("Purge: {$n} computed-Kante(n) gelöscht.");

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $minConf = (float) $this->option('min-confidence');
        $weightFactor = (float) $this->option('weight-factor');
        $teamId = (int) $this->option('team');

        $this->info(($apply ? 'APPLY' : 'DRY-RUN')." — min-confidence={$minConf}, weight-factor={$weightFactor}, team={$teamId}");
        $stats = $svc->project($apply, $minConf, $teamId, $weightFactor);

        $this->table(['Kennzahl', 'Wert'], [
            ['computed Anker-Paare (gesamt)', $stats['computed_pairs']],
            ['Kollisionen mit kuratiert (bleiben)', $stats['collisions_kept_curated']],
            ['Löcher (projizierbar)', $stats['holes']],
            ['  davon Typ aroma', $stats['holes_aroma']],
            ['  davon Typ kontrast', $stats['holes_kontrast']],
            ['computed-Kanten vorher', $stats['existing_computed_before']],
            [$apply ? 'EINGEFÜGT' : 'würde einfügen', $apply ? $stats['inserted'] : $stats['holes']],
        ]);

        if (! $apply) {
            $this->line('→ Dry-Run. Mit --apply schreiben. Rollback jederzeit via --purge.');
        } else {
            $this->info("Fertig — {$stats['inserted']} computed-Kante(n) projiziert. Danach recipeCohesion neu.");
        }

        return self::SUCCESS;
    }
}
