<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\Ai\WechselkursService;

/**
 * Täglicher EZB-Kurs USD für die KI-Kosten (Dominique 18.10.2026). Läuft 16:30 Europe/Berlin (EZB veröffentlicht ~16:00), idempotent je
 * EZB-Datum. Schlägt der Abruf fehl, bleibt der letzte Kurs (Log-Warnung, Exit-Code ≠ 0) — KI-Aufrufe sind davon nie betroffen.
 */
class WechselkursHolenCommand extends Command
{
    protected $signature = 'foodalchemist:wechselkurs-holen {--dry-run : nur holen und anzeigen, nicht speichern}';

    protected $description = 'Holt den EZB-Tageskurs EUR/USD und speichert ihn für die KI-Kosten (1 USD in EUR)';

    public function handle(WechselkursService $svc): int
    {
        $r = $svc->holen(! $this->option('dry-run'));
        if (! $r['ok']) {
            $this->warn('EZB-Kurs nicht geholt: '.$r['fehler'].' — der letzte Kurs bleibt.');

            return self::FAILURE;
        }
        $this->info(sprintf('EZB %s: 1 EUR = %s USD → 1 USD = %s EUR%s', $r['datum'], $r['ezb_kurs'], $r['usd_eur'],
            $this->option('dry-run') ? ' (Trockenlauf, nicht gespeichert)' : ($r['neu'] ? ' (neu gespeichert)' : ' (war schon da)')));

        return self::SUCCESS;
    }

    /** Scheduler: täglich 16:30 Europe/Berlin, außerhalb der Necta- und Gebühren-Läufe (nachts). */
    public static function planen(\Illuminate\Console\Scheduling\Schedule $schedule): void
    {
        $schedule->command(self::class)
            ->dailyAt(config('foodalchemist.scheduler.wechselkurs_zeit', '16:30'))
            ->timezone('Europe/Berlin')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->description('FoodAlchemist: EZB-Wechselkurs EUR/USD für die KI-Kosten holen');
    }
}
