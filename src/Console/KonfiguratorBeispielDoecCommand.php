<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\KonfiguratorPricingService;
use Platform\FoodAlchemist\Support\KonfiguratorBeispielDoec;

/**
 * Spec 94 · Legt den DOEC-Beispiel-Datensatz (Leistungs-Vorlagen, Konfigurator „Diva.licious 2026“, vier Pakete) in einem
 * Team an. `--concept="Culture=12/34"` verknüpft Hauptkonzepte (Lunch-Id/Dinner-Id, eine Seite darf leer sein),
 * `--addon="Mitternachtsnack=56"` Add-ons (bekannte DOEC-Titel bekommen Abschnitt, Tageszeit und Claim).
 * Ohne `--anlegen` nur Vorschau der Vorlagen (schreibt nichts).
 */
class KonfiguratorBeispielDoecCommand extends Command
{
    protected $signature = 'foodalchemist:konfigurator-beispiel-doec
        {--team= : Team, in dem der Datensatz entsteht}
        {--concept=* : Hauptkonzept als Titel=LunchId/DinnerId}
        {--addon=* : Add-on als Titel=ConceptId (Titel wie DOEC: Captain Breakfast, Captain on Board, Afternoon Break, Mitternachtsnack)}
        {--anlegen : wirklich anlegen (sonst nur Vorschau)}';

    protected $description = 'Spec 94: DOEC-Beispiel-Datensatz für den Konfigurator anlegen (Vorschau ohne --anlegen)';

    public function handle(KonfiguratorBeispielDoec $beispiel, KonfiguratorPricingService $pricing): int
    {
        $team = Team::find((int) $this->option('team'));
        if ($team === null) {
            $this->error('Team fehlt: --team=<id>');

            return self::FAILURE;
        }
        $this->table(['Kategorie', 'Vorlage', 'Regel', 'EK'], array_map(fn ($v) => [$v['kategorie'], $v['bezeichnung'], $v['mengen_regel'], $v['ek_satz']], KonfiguratorBeispielDoec::vorlagen()));
        if (! $this->option('anlegen')) {
            $this->info('Vorschau — mit --anlegen im Team „' . $team->name . '“ anlegen.');

            return self::SUCCESS;
        }

        $konzepte = [];
        foreach ((array) $this->option('concept') as $angabe) {
            if (! preg_match('/^(.+)=(\d*)\/(\d*)$/', (string) $angabe, $m)) {
                $this->error("Concept-Angabe „{$angabe}“: erwartet Titel=LunchId/DinnerId");

                return self::FAILURE;
            }
            $konzepte[trim($m[1])] = ['lunch' => $m[2] !== '' ? (int) $m[2] : null, 'dinner' => $m[3] !== '' ? (int) $m[3] : null];
        }

        $addons = [];
        foreach ((array) $this->option('addon') as $angabe) {
            if (! preg_match('/^(.+)=(\d+)$/', (string) $angabe, $m)) {
                $this->error("Add-on-Angabe „{$angabe}“: erwartet Titel=ConceptId");

                return self::FAILURE;
            }
            $addons[trim($m[1])] = (int) $m[2];
        }

        $k = $beispiel->anlegen($team, $konzepte, $addons);
        $this->info("Konfigurator #{$k->id} „{$k->name}“ angelegt.");
        foreach ($pricing->matrix($k)['warnungen'] as $w) {
            $this->line('  ' . $w);
        }

        return self::SUCCESS;
    }
}
