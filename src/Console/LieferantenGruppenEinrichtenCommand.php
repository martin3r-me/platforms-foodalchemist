<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\SupplierGroupService;

/**
 * Legt die sechs Lieferanten-Gruppen als GLOBALEN Basis-Katalog an (team_id NULL) — idempotent, Dry-Run als Default; Kommando statt Migration (Spec 82 §6).
 */
class LieferantenGruppenEinrichtenCommand extends Command
{
    protected $signature = 'foodalchemist:lieferanten-gruppen-einrichten
        {--apply : Schreiben (sonst Dry-Run)}';

    protected $description = 'Legt die Lieferanten-Gruppen als globalen Basis-Katalog an (Lebensmittel-Großhandel, Frische, Getränke, Non-Food, Verleih, Sonstiges) — idempotent';

    public function handle(SupplierGroupService $svc): int
    {
        $apply = (bool) $this->option('apply');
        if (! $apply) {
            $this->warn('DRY-RUN — es wird nichts geschrieben. Mit --apply ausführen.');
        }
        $r = $svc->einrichten($apply);
        $this->line(count($r['angelegt']).' Gruppen '.($apply ? 'angelegt' : 'würden angelegt').', '.$r['vorhanden'].' waren schon da (global, team_id NULL)');
        foreach ($r['angelegt'] as $name) {
            $this->line('  '.$name);
        }

        return self::SUCCESS;
    }
}
