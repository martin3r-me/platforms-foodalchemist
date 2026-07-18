<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\ProcessAnchorService;

/**
 * 05·P5 (Etappe 1) — Prozessanker-Parser: erdet die vier Prozess-/Kocharomen-
 * Anker (roestaromen/karamell/rauch/ferment) deterministisch aus dem
 * Zubereitungstext. Kein Marker im Text → kein Anker (keine Erfindung).
 *
 * Default = dry-run (nur Umfang berichten). --apply schreibt (source='parser',
 * fremde manual/ki/auto-Anker bleiben unangetastet). --verify berichtet nur die
 * Ist-Abdeckung. Idempotent — re-runbar; ⚠️ vor --apply Backup der Master-DB.
 *
 * `--mode=ki` ist Etappe 2 (mehrdeutige Prep-Texte via LLM) und hier noch NICHT
 * implementiert — der Parser-Modus deckt bewusst nur die eindeutigen Fälle.
 */
class ProcessAnchorGroundCommand extends Command
{
    protected $signature = 'foodalchemist:process-anchor-ground
        {--team= : Team-ID (Katalog-Besitzer; default: alle Teams)}
        {--recipe= : nur dieses Rezept (id)}
        {--missing-only : nur Rezepte ohne jegliche Prozessanker (Lücken-Fill)}
        {--limit= : max. Anzahl Rezepte (Test/Teillauf)}
        {--mode=parser : parser (deterministisch, Etappe 1) — ki ist Etappe 2 (n/a)}
        {--apply : schreiben; ohne = dry-run (nur zählen)}
        {--verify : nur Ist-Abdeckung der parser-Anker berichten (kein Schreiben)}';

    protected $description = 'P5: Prozessanker (roest/karamell/rauch/ferment) deterministisch aus dem Zubereitungstext erden.';

    public function handle(ProcessAnchorService $svc): int
    {
        ini_set('memory_limit', '1024M');

        $mode = (string) $this->option('mode');
        if ($mode !== 'parser') {
            $this->error("Modus \"{$mode}\" ist nicht implementiert — nur --mode=parser (Etappe 1). KI-Rest = Etappe 2.");

            return self::FAILURE;
        }

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
            $this->info('Prozessanker-Abdeckung' . ($team ? " (Team {$team->id})" : ' (alle Teams)') . ':');
            $this->table(
                ['Rezepte', 'Anker gesamt', 'davon parser', 'Rezepte mit Anker'],
                [[$c['recipes'], $c['anchors_total'], $c['anchors_parser'], $c['recipes_with_anchor']]],
            );

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $recipeId = $this->option('recipe') !== null ? (int) $this->option('recipe') : null;

        $stats = $svc->groundBulk(
            team: $team,
            apply: $apply,
            missingOnly: (bool) $this->option('missing-only'),
            limit: $limit,
            recipeId: $recipeId,
        );

        $perAnker = [];
        foreach ($stats['per_anchor'] as $slug => $n) {
            $perAnker[] = "{$slug}:{$n}";
        }
        $perAnkerStr = $perAnker === [] ? '—' : implode(' · ', $perAnker);

        if (! $apply) {
            $this->warn(
                "DRY-RUN — {$stats['scanned']} Rezepte gescannt; würde {$stats['recipes_touched']} Rezepte anfassen "
                . "(+{$stats['added']} Anker / -{$stats['removed']} veraltete parser-Anker). Neu je Anker: {$perAnkerStr}. "
                . 'Mit --apply schreiben (vorher Backup!).',
            );

            return self::SUCCESS;
        }

        $this->info(
            "Fertig — {$stats['scanned']} Rezepte gescannt, {$stats['recipes_touched']} angefasst "
            . "(+{$stats['added']} Anker / -{$stats['removed']} veraltete parser-Anker). Neu je Anker: {$perAnkerStr}.",
        );

        return self::SUCCESS;
    }
}
