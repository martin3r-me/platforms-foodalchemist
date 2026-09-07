<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\RecipeService;

/**
 * ONE-SHOT: `deferred.reuse` an BESTEHENDEN Übernahme-Schritten nachtragen.
 *
 * Ohne diesen Lauf wirkt der Fix nur auf NEUE Kaskaden. Bestehende `skipped`-Schritte tragen
 * keinen Reifegrad-Marker, also greift nichts, was daran hängt: keine „Bestand unfertig"-Zeile,
 * kein Anreicherungs-Badge, und `recomputeRunStatus` lässt den Lauf weiter als `done` gelten.
 * Der Anlassfall (demo, Lauf 65 / Step 460 „Basisrezept: Gemüsebrühe") sähe damit unverändert
 * aus — und genau den will Dominique nachprüfen.
 *
 * Schreibt NUR `deferred.reuse` und bewertet danach die betroffenen Läufe neu. Rührt weder
 * Rezepte noch Step-Status an; Schritte, die den Marker schon tragen, bleiben unberührt
 * (idempotent, ausser mit --force).
 */
class ReuseReifeBackfillCommand extends Command
{
    protected $signature = 'foodalchemist:reuse-reife-backfill
        {--team= : nur dieses Team (Default: alle)}
        {--run= : nur diesen Kaskaden-Lauf}
        {--force : auch Schritte neu bewerten, die den Marker schon tragen}
        {--apply : schreiben (ohne dieses Flag nur Bericht)}';

    protected $description = 'Traegt den Reifegrad (deferred.reuse) an bestehenden uebernommenen Kaskaden-Schritten nach';

    public function handle(RecipeService $rezepte, PlanningCascadeService $kaskade): int
    {
        $q = FoodAlchemistCascadeRunStep::query()
            ->where('status', 'skipped')
            ->where('ref_type', 'recipe')
            ->whereNotNull('ref_id');
        if (($team = (int) $this->option('team')) > 0) {
            $q->where('team_id', $team);
        }
        if (($run = (int) $this->option('run')) > 0) {
            $q->where('cascade_run_id', $run);
        }
        $steps = $q->orderBy('id')->get();

        $unreif = 0;
        $reif = 0;
        $uebersprungen = 0;
        $ohneRezept = 0;
        $laeufe = [];
        $schreiben = [];

        foreach ($steps as $step) {
            $deferred = is_array($step->deferred) ? $step->deferred : [];
            if (is_array($deferred['reuse'] ?? null) && ! $this->option('force')) {
                $uebersprungen++;

                continue;
            }
            // `hardstop`-Zeilen sind technisch auch `skipped`, aber KEINE Übernahme (kein
            // Bestands-Treffer, ref_id ist dort null) — sie fallen schon durch das ref_id-Gate.
            $stepTeam = Team::find((int) $step->team_id);
            $recipe = $stepTeam === null ? null
                : FoodAlchemistRecipe::visibleToTeam($stepTeam)->find((int) $step->ref_id);
            if ($recipe === null) {
                $ohneRezept++;

                continue;
            }
            $reife = $rezepte->reifegrad($recipe);
            $marker = [
                'reif' => $reife['reif'],
                // Besitz aus dem Step-Team, nicht aus dem aktiven Nutzer: der Lauf gehoert
                // dem Team, das ihn gefahren hat.
                'eigen' => (int) ($recipe->team_id ?? 0) === (int) $step->team_id,
                'luecken' => $reife['luecken'],
                'status' => (string) ($recipe->status?->value ?? ''),
            ];
            $reife['reif'] ? $reif++ : $unreif++;
            $schreiben[] = [$step, $deferred, $marker];
            if (! $reife['reif'] && $step->cascade_run_id !== null) {
                $laeufe[(int) $step->cascade_run_id] = true;
            }
            if (! $reife['reif']) {
                $this->line(sprintf('    Lauf %-5s Step %-6s %s → %s',
                    $step->cascade_run_id, $step->id,
                    mb_strimwidth((string) $step->label, 0, 42, '…'),
                    implode(' · ', $reife['luecken'])));
            }
        }

        $this->line(sprintf('  %d Uebernahme-Schritte · %d reif · %d UNREIF · %d schon markiert · %d ohne sichtbares Rezept',
            $steps->count(), $reif, $unreif, $uebersprungen, $ohneRezept));

        if (! $this->option('apply')) {
            $this->line('  Bericht only — mit --apply schreiben.');

            return self::SUCCESS;
        }

        foreach ($schreiben as [$step, $deferred, $marker]) {
            $deferred['reuse'] = $marker;
            $step->update(['deferred' => $deferred]);
        }
        // Erst NACH allen Markern neu bewerten: ein Lauf kann mehrere Uebernahmen tragen.
        foreach (array_keys($laeufe) as $runId) {
            $kaskade->recomputeRunStatus((int) $runId);
        }
        $this->info(sprintf('  %d Marker geschrieben · %d Laeufe neu bewertet.', count($schreiben), count($laeufe)));

        return self::SUCCESS;
    }
}
