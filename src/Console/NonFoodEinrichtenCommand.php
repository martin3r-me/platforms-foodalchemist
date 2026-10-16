<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe;
use Platform\FoodAlchemist\Services\NonFoodService;
use Platform\FoodAlchemist\Support\NonFoodRegeln;

/**
 * Legt die Warengruppe 16 „Non-Food“ mit ihren sechs Unterkategorien an (Dominique 2026-10-16).
 *
 * Bewusst ein KOMMANDO, keine Migration (Spec 82 §6): Stammdaten gehören in kein `migrate`. Idempotent; Dry-Run ist der Default.
 * Ohne --team gilt das Team, dem die Warengruppe 15 gehört (der Katalog-Besitzer).
 */
class NonFoodEinrichtenCommand extends Command
{
    protected $signature = 'foodalchemist:nonfood-einrichten
        {--team= : Ziel-Team-ID (ohne Angabe: das Team der Warengruppe 15)}
        {--apply : Schreiben (sonst Dry-Run)}';

    protected $description = 'Legt die Warengruppe 16 Non-Food und ihre Unterkategorien an — idempotent';

    public function handle(NonFoodService $svc): int
    {
        $teamId = $this->option('team') !== null
            ? (int) $this->option('team')
            : FoodAlchemistLookupWarengruppe::query()->where('code', '15')->value('team_id');
        $team = $teamId ? Team::find($teamId) : null;
        if ($team === null) {
            $this->error('Kein Ziel-Team gefunden (--team=ID angeben).');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        if (! $apply) {
            $this->warn('DRY-RUN — es wird nichts geschrieben. Mit --apply ausführen.');
        }

        $r = $svc->einrichten($team, $apply);
        $this->line('Team '.$team->id.': Warengruppe '.NonFoodRegeln::WG_NAME.' — '.($r['wg_angelegt'] ? ($apply ? 'angelegt' : 'würde angelegt') : 'war schon da'));
        foreach ($r['unterkategorien_angelegt'] as $name) {
            $this->line('  Unterkategorie '.$name.' — '.($apply ? 'angelegt' : 'würde angelegt'));
        }
        if ($r['unterkategorien_angelegt'] === []) {
            $this->line('  alle Unterkategorien waren schon da');
        }

        return self::SUCCESS;
    }
}
