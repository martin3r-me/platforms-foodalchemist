<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Pairing\RezeptProfil;

/**
 * Spec 60 · P5: Aromenprofile der Rezepte bauen (Kern-Anker mit Anteil, Eigenschaften, offene
 * Bedarfe). Wiederholbar: unveränderte Profile (gleicher Quell-Hash) werden nicht neu geschrieben.
 *
 *   php artisan foodalchemist:rezept-profile --rezept=123
 *   php artisan foodalchemist:rezept-profile --alle
 */
class RezeptProfileCommand extends Command
{
    protected $signature = 'foodalchemist:rezept-profile {--rezept= : eine Rezept-ID} {--alle : alle Rezepte}';

    protected $description = 'Spec 60: Aromenprofile der Rezepte bauen (Anker-Anteile, Eigenschaften, offene Bedarfe)';

    public function handle(RezeptProfil $profil): int
    {
        $ids = $this->option('rezept') !== null
            ? [(int) $this->option('rezept')]
            : ($this->option('alle') ? DB::table('foodalchemist_recipes')->whereNull('deleted_at')->orderBy('id')->pluck('id')->all() : []);
        if ($ids === []) {
            $this->error('--rezept=ID oder --alle angeben.');

            return self::FAILURE;
        }
        $bar = $this->output->createProgressBar(count($ids));
        $mitAnker = 0;
        foreach ($ids as $id) {
            $p = $profil->fuer((int) $id);
            $mitAnker += $p['anker'] !== [] ? 1 : 0;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
        $this->info(count($ids)." Rezepte, davon {$mitAnker} mit Aromenprofil.");

        return self::SUCCESS;
    }
}
