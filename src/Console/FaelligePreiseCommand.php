<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportLine as Zeile;
use Platform\FoodAlchemist\Models\FoodAlchemistPriceImportRun as Lauf;
use Platform\FoodAlchemist\Services\PreislistenImportService;
use Platform\FoodAlchemist\Services\Stammdaten\StammdatenUebernahmeService;

/**
 * Fällige vorgemerkte Preise schreiben (Kurator 29.10.2026): Preislisten- UND Stammdaten-Läufe, deren Zeilen im Status „zukünftig“ stehen und deren
 * „Gültig ab“ erreicht ist. Täglich früh im Scheduler (foodalchemist.scheduler.faellige_preise_zeit), idempotent (ein zweiter Lauf am selben Tag findet nichts),
 * Protokoll im Log-Kanal und am Lauf (`counts.faellige_preise_zuletzt`). `--dry-run` zeigt nur, was fällig wäre.
 */
class FaelligePreiseCommand extends Command
{
    protected $signature = 'foodalchemist:faellige-preise
        {--team= : nur dieses Team (ID)}
        {--dry-run : nur zählen, nichts schreiben}';

    protected $description = 'Vorgemerkte Preise (Gültig ab erreicht) aus Preislisten- und Stammdaten-Läufen schreiben (idempotent).';

    public function handle(PreislistenImportService $liste, StammdatenUebernahmeService $stamm): int
    {
        $heute = now()->toDateString();
        $laeufe = Lauf::query()
            ->when($this->option('team'), fn ($q) => $q->where('team_id', (int) $this->option('team')))
            ->whereExists(fn ($e) => $e->selectRaw('1')->from('foodalchemist_price_import_lines as l')->whereColumn('l.run_id', 'foodalchemist_price_import_runs.id')
                ->where('l.status', Zeile::STATUS_ZUKUENFTIG)->whereDate('l.valid_from', '<=', $heute)->whereNull('l.deleted_at'))
            ->orderBy('id')->get();

        $summe = ['laeufe' => 0, 'preise' => 0, 'fehler' => 0];
        foreach ($laeufe as $lauf) {
            $team = Team::find($lauf->team_id);
            if ($team === null) {
                continue;
            }
            $faellig = Zeile::where('run_id', $lauf->id)->where('status', Zeile::STATUS_ZUKUENFTIG)->whereDate('valid_from', '<=', $heute)->count();
            if ($this->option('dry-run')) {
                $this->line("Lauf #{$lauf->id} ({$lauf->art}, Team {$team->id}): {$faellig} Preise fällig");
                $summe['preise'] += $faellig;
                $summe['laeufe']++;

                continue;
            }
            try {
                $b = $lauf->art === Lauf::ART_STAMMDATEN ? $stamm->faelligePreise($team, $lauf) : $liste->faelligeZukunftspreise($team, $lauf);
                $n = (int) ($b['faellige_preise'] ?? $b['angewendet'] ?? 0);
                $summe['preise'] += $n;
                $summe['laeufe']++;
                $counts = (array) $lauf->fresh()->counts;
                $counts['faellige_preise_zuletzt'] = ['am' => $heute, 'anzahl' => $n];
                $lauf->update(['counts' => $counts]);
                Log::info("foodalchemist:faellige-preise Lauf #{$lauf->id} ({$lauf->art}, Team {$team->id}): {$n} Preise geschrieben.");
                $this->line("Lauf #{$lauf->id} ({$lauf->art}, Team {$team->id}): {$n} Preise geschrieben.");
            } catch (\Throwable $e) {
                $summe['fehler']++;
                Log::warning("foodalchemist:faellige-preise Lauf #{$lauf->id} fehlgeschlagen: {$e->getMessage()}");
                $this->error("Lauf #{$lauf->id}: {$e->getMessage()}");
            }
        }
        $this->info(($this->option('dry-run') ? 'Dry-run: ' : '') . "{$summe['laeufe']} Läufe, {$summe['preise']} Preise" . ($summe['fehler'] ? ", {$summe['fehler']} Fehler" : '') . '.');

        return $summe['fehler'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
