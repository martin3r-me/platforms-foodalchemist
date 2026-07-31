<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\RecipeStepService;

/**
 * Spec 27 Phase 1 — Bestands-Backfill: `recipes.preparation` (Markdown-Blob) in
 * strukturierte Schritte parsen und die bestehenden Fotos über ihre alte
 * `schritt_nr` an den Schritt gleicher `position` hängen.
 *
 * DETERMINISTISCH, keine KI (reine Regex-Parse) → reproduzierbar, kein Provider
 * nötig. Fotos mit `schritt_nr = 0` oder ohne passende Position bleiben bewusst
 * unverlinkt („allgemeines" Rezept-Foto) statt falsch zugeordnet zu werden.
 *
 * Default = dry-run (nur Umfang berichten). --apply schreibt. --verify berichtet
 * nur die Ist-Abdeckung. Idempotent — Rezepte mit vorhandenen Schritten werden
 * übersprungen; ⚠️ vor --apply Backup der Master-DB ziehen.
 */
class StepsBackfillCommand extends Command
{
    protected $signature = 'foodalchemist:steps-backfill
        {--team= : Team-ID (default: alle Teams)}
        {--recipe= : nur dieses Rezept (id)}
        {--limit= : max. Anzahl Rezepte (Test/Teillauf)}
        {--apply : schreiben; ohne = dry-run (nur zählen)}
        {--verify : nur Ist-Abdeckung berichten (kein Schreiben)}';

    protected $description = 'Spec 27: Zubereitungs-Markdown in Schritte parsen + Schritt-Fotos verlinken (deterministisch).';

    public function handle(RecipeStepService $svc): int
    {
        ini_set('memory_limit', '1024M');

        $team = null;
        if ($this->option('team') !== null) {
            $team = Team::find((int) $this->option('team'));
            if ($team === null) {
                $this->error('Team ' . $this->option('team') . ' nicht gefunden.');

                return self::FAILURE;
            }
        }

        if ($this->option('verify')) {
            $c = $svc->coverage($team);
            $this->info('Schritt-Abdeckung' . ($team ? " (Team {$team->id})" : ' (alle Teams)') . ':');
            $this->table(
                ['Rezepte', 'mit Zubereitungstext', 'mit Schritten', 'Schritte gesamt', 'Fotos', 'davon verlinkt'],
                [[
                    $c['recipes'], $c['recipes_with_prep'], $c['recipes_with_steps'],
                    $c['steps_total'], $c['photos_total'], $c['photos_linked'],
                ]],
            );

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');

        $s = $svc->backfillBulk(
            team: $team,
            apply: $apply,
            limit: $this->option('limit') !== null ? (int) $this->option('limit') : null,
            recipeId: $this->option('recipe') !== null ? (int) $this->option('recipe') : null,
        );

        $bericht = "{$s['scanned']} Rezepte gescannt · {$s['recipes_touched']} mit Schritten "
            . "({$s['steps_created']} Schritte) · {$s['photos_linked']} Fotos verlinkt, "
            . "{$s['photos_unassignable']} nicht zuordenbar · übersprungen: "
            . "{$s['skipped_no_prep']} ohne Text, {$s['skipped_has_steps']} haben schon Schritte";

        if (! $apply) {
            $this->warn("DRY-RUN — {$bericht}. Mit --apply schreiben (vorher Backup!).");

            return self::SUCCESS;
        }

        $this->info("Fertig — {$bericht}.");

        return self::SUCCESS;
    }
}
