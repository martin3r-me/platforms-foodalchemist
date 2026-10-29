<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\LeadLaService;

/**
 * Bestand: GPs, deren Lead bei einem inaktiven oder gesperrten Lieferanten liegt (Kurator 29.10.2026). Reiner Dry-run — dieselbe Vorschau wie
 * `foodalchemist.lead_la.REPICK` mit nur_inaktive_lieferanten=true; Umstellen läuft bewusst nur dort (dry_run=false, gp_ids).
 */
class LeadLaInaktiveCommand extends Command
{
    protected $signature = 'foodalchemist:lead-la-inaktive
        {--team= : Team-ID (Katalog-Besitzer; default: alle Teams)}';

    protected $description = 'Dry-run: GPs mit Lead bei inaktivem/gesperrtem Lieferanten und der Repick-Vorschlag (schreibt nichts).';

    public function handle(LeadLaService $lead): int
    {
        $teams = $this->option('team') ? Team::whereKey((int) $this->option('team'))->get() : Team::query()->get();
        if ($teams->isEmpty()) {
            $this->error('Kein Team gefunden (--team=ID prüfen).');

            return self::FAILURE;
        }

        foreach ($teams as $team) {
            $v = $lead->repickVorschau($team, null, null, true);
            if ($v['geprueft'] === 0) {
                continue;
            }
            $this->info("Team {$team->id} ({$team->name}) — {$v['geprueft']} GPs mit Lead bei inaktivem/gesperrtem Lieferanten:");
            $this->table(['Kategorie', 'Anzahl'], [
                ['Wechsel zu bestellbarem Lieferanten möglich', count($v['wechsel'])],
                ['kein bestellbarer Ersatz (bleibt als Ausweich)', $v['kein_aktiver_ersatz']],
                ['manuell gesetzt (geschützt)', $v['manuell_geschuetzt']],
                ['Ersatz ohne Preis', $v['ohne_preis']],
            ]);
            $this->table(['GP', 'bisher', 'neu', 'Rezepte'], array_map(fn ($w) => [
                $w['gp_id'] . ' ' . $w['gp'], $w['alt_la_id'] . ' ' . $w['alt_lieferant'], $w['neu_la_id'] . ' ' . $w['neu_lieferant'], $w['rezepte'],
            ], $v['wechsel']));
        }
        $this->warn('Dry-run — nichts geschrieben. Umstellen: lead_la.REPICK (dry_run=false, gp_ids) nach Freigabe.');

        return self::SUCCESS;
    }
}
