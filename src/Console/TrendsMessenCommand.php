<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\TrendSignalService;

/**
 * Spec 79 · Wöchentliche Google-Trends-Messung je Trend über DataForSEO. Läuft nur für Teams, die es in
 * den Einstellungen eingeschaltet haben, misst jeden Trend höchstens alle 6 Tage und stoppt am Monatsbudget.
 */
class TrendsMessenCommand extends Command
{
    protected $signature = 'foodalchemist:trends-messen
        {--team= : nur dieses Team (ID), sonst alle mit eingeschalteter Messung}';

    protected $description = 'Trendradar → Google Trends je Trend messen (DataForSEO, mit Monatsbudget)';

    public function handle(TrendSignalService $signale, TeamSettingsService $settings): int
    {
        if (! $signale->anbindungVorhanden()) {
            $this->warn('DataForSEO-Anbindung (Integrations-Modul) fehlt — nichts zu tun.');

            return self::SUCCESS;
        }
        $teams = $this->option('team') ? Team::whereKey((int) $this->option('team'))->get() : Team::query()->get();
        foreach ($teams as $team) {
            if (! $settings->trendDataForSeoAktiv($team)) {
                continue;
            }
            $e = $signale->wochenlauf($team);
            $this->line(sprintf('Team %d (%s): %d gemessen, %d übersprungen, %d Fehler.', $team->id, $team->name,
                $e['gemessen'], $e['uebersprungen'], count($e['fehler'])));
            foreach ($e['fehler'] as $f) {
                $this->warn('  '.$f);
            }
        }

        return self::SUCCESS;
    }
}
