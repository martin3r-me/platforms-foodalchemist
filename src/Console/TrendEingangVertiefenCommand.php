<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Services\TrendVertiefungService;

/**
 * Tageslauf der Vertiefung: je Team höchstens `trends.vertiefung.auto_je_tag` Marktbewegungen bzw. Einträge aus harten
 * Quellen ohne Vertiefung anstoßen (Plattform-KI, eigenes Trend-KI-Budget). Der Scheduler-Eintrag ist standardmäßig aus
 * (config foodalchemist.scheduler.trend_vertiefung_enabled).
 */
class TrendEingangVertiefenCommand extends Command
{
    protected $signature = 'foodalchemist:trend-eingang-vertiefen {--team= : nur dieses Team (ID)}';

    protected $description = 'Trendradar → Marktbewegungen vertiefen (Kernaussagen mit Zahl und Beleg aus der verlinkten Seite)';

    public function handle(TrendVertiefungService $svc): int
    {
        $teams = FoodAlchemistTrendEingang::query()->whereNull('vertiefung_status')->distinct()->pluck('team_id');
        if ($this->option('team')) {
            $teams = $teams->intersect([(int) $this->option('team')]);
        }
        $summe = 0;
        foreach (Team::whereIn('id', $teams)->orderBy('id')->get() as $team) {
            $ids = $svc->autoLauf($team);
            $summe += count($ids);
            if ($ids !== []) {
                $this->line(sprintf('Team %d: %d angestoßen (%s)', $team->id, count($ids), implode(', ', $ids)));
            }
        }
        $this->info($summe.' Einträge zur Vertiefung angestoßen.');

        return self::SUCCESS;
    }
}
