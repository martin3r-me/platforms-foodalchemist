<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Services\Trends\TrendAutomatikService;

/**
 * Spec 93 C · Täglicher Lauf der Trend-Automatik: ordnet die automatischen Themen nach Regeln ein und legt (je Stufe) Trends
 * an. Läuft NACH dem Quellen-Abruf (05:50) und dem Erschließen (Queue). Teams mit Stufe „aus“ oder im Schatten-Modus
 * schreiben nichts. Der Scheduler-Eintrag ist standardmäßig aus (config foodalchemist.scheduler.trend_automatik_enabled).
 */
class TrendAutomatikCommand extends Command
{
    protected $signature = 'foodalchemist:trend-automatik
        {--team= : nur dieses Team (ID)}';

    protected $description = 'Trendradar → Themen nach Regeln einordnen und Trends anlegen (je Stufe; Schatten-Modus schreibt nichts)';

    public function handle(TrendAutomatikService $automatik, TeamSettingsService $settings): int
    {
        $teams = $this->option('team') ? Team::whereKey((int) $this->option('team'))->get() : Team::query()->orderBy('id')->get();
        foreach ($teams as $team) {
            $stufe = $settings->trendAutomatik($team);
            if ($stufe === 'aus' || $settings->trendAutomatikSchatten($team)) {
                if ($this->option('team')) {
                    $this->line(sprintf('Team %d: Stufe %s%s — nichts geschrieben.', $team->id, $stufe, $settings->trendAutomatikSchatten($team) ? ', Schatten-Modus' : ''));
                }

                continue;
            }
            $r = \Platform\FoodAlchemist\Support\FaTeam::imTeam($team, fn () => $automatik->lauf($team));
            $this->line(sprintf('Team %d (%s): %d Themen, %d neue Trends, %d warten (Grenze).', $team->id, $team->name, count($r['liste']), $r['neue_trends'], $r['gestaut']));
        }

        return self::SUCCESS;
    }
}
