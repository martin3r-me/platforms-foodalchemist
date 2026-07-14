<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;

/**
 * Etappe 1 / P4 — Bulk-Recompute der Rezept-Aggregationen (EK/Yield/Allergene/
 * Zusatzstoffe/Nährwerte/Darreichungs-Preise). Wrappt RecipeRecomputeService.
 *
 * `--all` rechnet ALLE Rezepte topologisch (Kinder vor Eltern, diamond-sicher).
 * `--recipe=ID [--propagate]` rechnet ein Rezept (optional inkl. transitiver Eltern).
 * Nach P2 (Lead-LA-Repick) ausführen, damit Basisrezepte + Gerichte die geheilten
 * GP-Preise erben. Idempotent (rechnet Aggregate aus dem Ist-Stand neu).
 *
 * Default = nur Umfang berichten; --apply führt aus (Kaskaden-Mutation → Backup!).
 */
class RecomputeCommand extends Command
{
    protected $signature = 'foodalchemist:recompute
        {--all : alle Rezepte (topologisch)}
        {--recipe= : nur dieses Rezept (id)}
        {--propagate : mit --recipe zusätzlich die transitiven Eltern}
        {--apply : ausführen; ohne = nur Umfang berichten}';

    protected $description = 'P4: Bulk-Recompute der Rezept-Aggregationen (EK/Allergene/Yield/Darreichung).';

    public function handle(RecipeRecomputeService $svc): int
    {
        ini_set('memory_limit', '1024M');

        $apply = (bool) $this->option('apply');
        $recipeId = $this->option('recipe') !== null ? (int) $this->option('recipe') : null;

        if (! $this->option('all') && $recipeId === null) {
            $this->error('Entweder --all oder --recipe=ID angeben.');

            return self::FAILURE;
        }

        if ($recipeId !== null) {
            if (! FoodAlchemistRecipe::whereKey($recipeId)->exists()) {
                $this->error("Rezept {$recipeId} nicht gefunden.");

                return self::FAILURE;
            }
            if (! $apply) {
                $this->warn("DRY-RUN — würde Rezept {$recipeId}" . ($this->option('propagate') ? ' + transitive Eltern' : '') . ' neu rechnen.');

                return self::SUCCESS;
            }
            $this->option('propagate')
                ? $svc->recomputeAndPropagate($recipeId)
                : $svc->recomputePipeline($recipeId);
            $this->info("Rezept {$recipeId} neu gerechnet" . ($this->option('propagate') ? ' (inkl. Eltern)' : '') . '.');

            return self::SUCCESS;
        }

        // --all
        $gesamt = FoodAlchemistRecipe::count();
        if (! $apply) {
            $this->warn("DRY-RUN — würde {$gesamt} Rezepte topologisch neu rechnen. Mit --apply ausführen (vorher Backup!).");

            return self::SUCCESS;
        }

        $this->info("Recompute über {$gesamt} Rezepte (topologisch) …");
        try {
            $r = $svc->recomputeAll();
        } catch (\RuntimeException $e) {
            $this->error('Abbruch: ' . $e->getMessage());

            return self::FAILURE;
        }
        $this->info("Fertig — {$r['berechnet']} Rezepte gerechnet, Reihenfolge ok: " . ($r['reihenfolge_ok'] ? 'ja' : 'nein') . '.');

        return self::SUCCESS;
    }
}
