<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Services\NonFoodService;
use Platform\FoodAlchemist\Support\NonFoodRegeln;

/**
 * Legt die Warengruppe 16 „Non-Food“ mit ihren sieben Unterkategorien an (nummeriert nach §8.18.3, 27.10.) (Dominique 2026-10-16).
 *
 * Bewusst ein KOMMANDO, keine Migration (Spec 82 §6): Stammdaten gehören in kein `migrate`. Idempotent; Dry-Run ist der Default.
 * Plattform-Basis 18.10.: Warengruppen sind global — kein Ziel-Team mehr; --team wird nur noch geduldet und ignoriert.
 */
class NonFoodEinrichtenCommand extends Command
{
    protected $signature = 'foodalchemist:nonfood-einrichten
        {--team= : veraltet, wird ignoriert (Warengruppen sind global)}
        {--apply : Schreiben (sonst Dry-Run)}';

    protected $description = 'Legt die Warengruppe 16 Non-Food und ihre Unterkategorien an — idempotent';

    public function handle(NonFoodService $svc): int
    {
        if ($this->option('team') !== null) {
            $this->warn('--team wird ignoriert: Warengruppen und Sub-Kategorien sind global (Plattform-Basis).');
        }
        $apply = (bool) $this->option('apply');
        if (! $apply) {
            $this->warn('DRY-RUN — es wird nichts geschrieben. Mit --apply ausführen.');
        }

        $r = $svc->einrichten($apply);
        $this->line('Global: Warengruppe '.NonFoodRegeln::WG_NAME.' — '.($r['wg_angelegt'] ? ($apply ? 'angelegt' : 'würde angelegt') : 'war schon da'));
        foreach ($r['unterkategorien_angelegt'] as $name) {
            $this->line('  Unterkategorie '.$name.' — '.($apply ? 'angelegt' : 'würde angelegt'));
        }
        foreach ($r['umzubenennen'] as $alt => $neu) {
            $this->line('  Unterkategorie „'.$alt.'“ steht noch unter altem Namen — nicht doppelt angelegt, Umbenennung auf „'.$neu.'“ macht der Datenlauf');
        }
        if ($r['unterkategorien_angelegt'] === [] && $r['umzubenennen'] === []) {
            $this->line('  alle Unterkategorien waren schon da');
        }

        return self::SUCCESS;
    }
}
