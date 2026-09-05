<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpAggregateService;

/**
 * Spec 50 · A8 — GPs zurück in die LA-Kaskade holen. **Default ist Trockenlauf.**
 *
 * Findet GPs, die einen KI-Allergen-Override tragen, OBWOHL ihre Lieferantenartikel ein
 * Allergenprofil liefern. Zwei Schäden stecken darin:
 *  1. Der KI-Wert steht als GL-01 §4.3 **Prio 1** über der LA-MAX-Auflösung — eine Schätzung
 *     verdeckt eine Messung, auf einem Compliance-Feld.
 *  2. `allergens_source='ki'` lässt {@see GpAggregateService::backfillAllergenKonfidenz} den
 *     GP überspringen: die Kaskade „LA fixen → GP heilt" ist für ihn tot.
 *
 * Der Wächter (`hatLaAllergenProfil`) verhindert neue Fälle; dieses Kommando räumt die
 * bestehenden ab. Es LÖSCHT Override-Werte — wo der LA nichts weiss, steht danach `unbekannt`
 * statt einer Schätzung. Das ist die fachlich richtige Richtung (F7.1: nie falsch-negativ
 * raten), aber es ändert Deklarationsdaten. Deshalb: erst lesen, dann `--apply`.
 *
 * `manual` bleibt unangetastet — ein Mensch darf die LA-Kette bewusst übersteuern.
 */
class AllergenLaVorrangCommand extends Command
{
    protected $signature = 'foodalchemist:allergen-la-vorrang
        {--team= : nur dieses Team (ID), sonst alle}
        {--apply : wirklich schreiben (ohne dieses Flag nur Vorschau)}';

    protected $description = 'A8: KI-Allergen-Overrides auf GPs mit LA-Profil auflösen, damit die LA-Kaskade wieder greift.';

    public function handle(GpAggregateService $agg): int
    {
        $apply = (bool) $this->option('apply');
        $teams = $this->option('team')
            ? Team::whereKey((int) $this->option('team'))->get()
            : Team::query()->get();

        $gesamt = 0;

        foreach ($teams as $team) {
            $kandidaten = FoodAlchemistGp::visibleToTeam($team)
                ->where('allergens_source', 'ki')
                ->orderBy('id')->get()
                ->filter(fn (FoodAlchemistGp $gp) => $gp->isOwnedBy($team) && $agg->hatLaAllergenProfil($gp));

            if ($kandidaten->isEmpty()) {
                continue;
            }

            $this->info("── Team {$team->id} ({$team->name}) — {$kandidaten->count()} GP(s) ──");
            $zeilen = [];

            foreach ($kandidaten as $gp) {
                $overrides = [];
                foreach (FoodAlchemistGp::ALLERGEN_FIELDS as $feld) {
                    if ($gp->getAttribute("allergen_{$feld}") !== null) {
                        $overrides[] = $feld;
                    }
                }
                $zeilen[] = [$gp->id, mb_strimwidth((string) $gp->name, 0, 40, '…'), count($overrides), implode(', ', array_slice($overrides, 0, 4))];

                if (! $apply) {
                    continue;
                }

                $reset = array_fill_keys(array_map(fn ($f) => "allergen_{$f}", FoodAlchemistGp::ALLERGEN_FIELDS), null);
                $gp->update([...$reset, 'allergens_source' => null, 'allergens_confidence' => null]);
                // Jetzt greift der Provenienz-Schutz nicht mehr — die LA-Kette rechnet neu.
                $agg->backfillAllergenKonfidenz($gp->fresh(), apply: true);
                $gesamt++;
            }

            $this->table(['GP-ID', 'Name', 'Overrides', 'Felder (Auszug)'], $zeilen);
        }

        if (! $apply) {
            $this->warn('Trockenlauf — nichts geschrieben. Mit --apply ausführen (vorher DB-Snapshot).');

            return self::SUCCESS;
        }

        $this->info("{$gesamt} GP(s) zurück in die LA-Kaskade geholt.");

        return self::SUCCESS;
    }
}
