<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\SupplierGroupService;

/**
 * Schlägt für jeden Lieferanten eine Gruppe vor (aus Necta-Branche und Name) — Dry-Run als Default. Von Hand gesetzte Gruppen bleiben immer
 * stehen; unklare Lieferanten bleiben ohne Gruppe und werden aufgelistet (Prüfliste).
 */
class LieferantenGruppenVorschlagenCommand extends Command
{
    protected $signature = 'foodalchemist:lieferanten-gruppen-vorschlagen
        {--team= : zusätzlich die Lieferanten dieses Teams (ohne Angabe: nur die globalen Lieferanten, der Stamm)}
        {--neu : auch schon vergebene Regel-Gruppen neu bewerten (von Hand gesetzte nie)}
        {--apply : Schreiben (sonst Dry-Run)}';

    protected $description = 'Schlägt Lieferanten-Gruppen aus Branche und Name vor; unklare Lieferanten kommen in die Prüfliste';

    public function handle(SupplierGroupService $svc): int
    {
        $team = $this->option('team') !== null ? Team::find((int) $this->option('team')) : null;
        if ($this->option('team') !== null && $team === null) {
            $this->error('Team nicht gefunden.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        if (! $apply) {
            $this->warn('DRY-RUN — es wird nichts geschrieben. Mit --apply ausführen.');
        }
        $r = $svc->vorschlagen($team, $apply, (bool) $this->option('neu'));
        $this->line('Lieferanten: '.$r['gesamt'].' · von Hand gesetzt (bleiben): '.$r['uebersprungen_manuell'].' · geschrieben: '.$r['geschrieben']);
        $this->table(['Gruppe', 'Vorschläge'], collect($r['vorgeschlagen'])->map(fn ($n, $code) => [$code, $n])->values()->all());
        if ($r['unklar'] !== []) {
            $this->warn(count($r['unklar']).' Lieferanten unklar (Gruppe von Hand wählen):');
            $this->table(['Id', 'Name', 'Branche'], array_map(fn ($u) => [$u['id'], $u['name'], $u['branch']], array_slice($r['unklar'], 0, 40)));
        }

        return self::SUCCESS;
    }
}
