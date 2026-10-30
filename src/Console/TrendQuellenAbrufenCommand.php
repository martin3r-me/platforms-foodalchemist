<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle;
use Platform\FoodAlchemist\Services\TrendEingangService;
use Platform\FoodAlchemist\Services\TrendQuellenService;

/**
 * Spec 93 A · Holt fällige plattform-Quellen des Trendradars in den Eingang (RSS/Atom/Übersicht, ohne KI, über den
 * SSRF-Schutz). mcp-Quellen ruft die Plattform nie ab. Fehler landen je Quelle in `letzter_fehler`, der Lauf geht weiter.
 * Der Scheduler-Eintrag ist standardmäßig aus (config foodalchemist.scheduler.trend_quellen_enabled).
 */
class TrendQuellenAbrufenCommand extends Command
{
    protected $signature = 'foodalchemist:trend-quellen-abrufen
        {--team= : nur Quellen dieses Teams (ID)}
        {--quelle= : nur diese Quelle (ID), auch wenn sie noch nicht fällig ist}';

    protected $description = 'Trendradar → fällige Quellen (Plattform-Weg) in den Eingang holen';

    public function handle(TrendQuellenService $quellen, TrendEingangService $eingang): int
    {
        $q = FoodAlchemistTrendQuelle::query()->where('abruf_weg', 'plattform')->where('aktiv', true);
        if ($this->option('team')) {
            $q->where('team_id', (int) $this->option('team'));
        }
        if ($this->option('quelle')) {
            $q->whereKey((int) $this->option('quelle'));
        }
        $summe = ['quellen' => 0, 'neu' => 0, 'fehler' => 0];
        foreach ($q->orderBy('id')->get() as $quelle) {
            if (! $this->option('quelle') && ! $quellen->istFaellig($quelle)) {
                continue;
            }
            $r = $eingang->quelleAbrufen($quelle);
            $summe['quellen']++;
            $summe['neu'] += $r['neu'];
            $this->line(sprintf('Quelle %d (%s, Team %d): %d neu, %d Dubletten, %d gefiltert%s', $quelle->id, $quelle->name, $quelle->team_id,
                $r['neu'], $r['dubletten'], $r['gefiltert'], $r['fehler'] !== null ? ' · Fehler: '.$r['fehler'] : ''));
            if ($r['fehler'] !== null) {
                $summe['fehler']++;
            }
        }
        $this->info(sprintf('%d Quellen abgerufen, %d neue Einträge, %d mit Fehler.', $summe['quellen'], $summe['neu'], $summe['fehler']));

        return self::SUCCESS;
    }
}
