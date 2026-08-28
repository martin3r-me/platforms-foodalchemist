<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Jobs\ConformanceCheckJob;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;

/**
 * Schicht 3 · Slice 6 — Konformitäts-Backfill über den GP-BESTAND.
 *
 * Die Event-Trigger (Mint, Freigabe) erreichen nur NEUE bzw. neu-freigegebene GPs;
 * dieser Lauf holt den Alt-Bestand ab. Default `--status=approved` = detect-only
 * (die Heilung guardt auf tentative → approved wird nur geprüft, nicht angetastet →
 * Verstoss erscheint als Signal im Cockpit, der Mensch arbeitet ab). `--status=tentative`
 * heilt zusätzlich (Review-Queue aufräumen) — bewusst opt-in.
 *
 * Wirft je GP EINEN ConformanceCheckJob (async, best-effort). Viele LLM-Calls →
 * OFF-PEAK fahren; `--dry-run` zählt nur, `--limit` chunkt.
 */
class ConformanceBackfillCommand extends Command
{
    protected $signature = 'foodalchemist:conformance-backfill
        {--team= : nur dieses Team (ID), sonst alle}
        {--status=approved : GP-Status-Filter (approved = detect-only | tentative = mit Heilung)}
        {--user= : User-ID für den KI-Kontext der Jobs (Pflicht ausser --dry-run)}
        {--limit= : max. Anzahl GPs über alle Teams je Lauf (Chunking, off-peak)}
        {--dry-run : nur zählen, nichts anstoßen}';

    protected $description = 'Konformitäts-Critic über den GP-Bestand nachziehen (Backfill; default approved = detect-only).';

    public function handle(): int
    {
        $status = (string) $this->option('status');
        if (! in_array($status, ['approved', 'tentative'], true)) {
            $this->error("--status muss approved oder tentative sein (war: {$status}).");

            return self::FAILURE;
        }

        $teams = $this->option('team')
            ? Team::whereKey((int) $this->option('team'))->get()
            : Team::query()->get();
        if ($teams->isEmpty()) {
            $this->error('Kein Team gefunden (--team=ID prüfen).');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $userId = $this->option('user') !== null ? (int) $this->option('user') : null;
        if (! $dry && $userId === null) {
            $this->error('--user=ID ist Pflicht (der KI-Kontext der Jobs braucht einen User) — oder --dry-run.');

            return self::FAILURE;
        }
        $limit = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;

        $gesamt = 0;
        foreach ($teams as $team) {
            // Platzhalter sind §3/§8-exempt (neutrale Abstrakta) → nie prüfen.
            $q = FoodAlchemistGp::visibleToTeam($team)
                ->where('status', $status)
                ->where('is_platzhalter', false);
            $offen = (clone $q)->count();

            $ziel = $limit !== null ? min($offen, max(0, $limit - $gesamt)) : $offen;
            if ($ziel <= 0) {
                $this->line("── Team {$team->id} ({$team->name}): {$offen} {$status}-GP(s) — 0 in diesem Lauf (Limit erreicht).");

                continue;
            }

            if ($dry) {
                $this->info("── Team {$team->id} ({$team->name}): {$ziel} von {$offen} {$status}-GP(s) würden geprüft (dry-run).");
                $gesamt += $ziel;

                continue;
            }

            $n = 0;
            (clone $q)->orderBy('id')->limit($ziel)->pluck('id')->each(function ($gpId) use ($team, $userId, &$n) {
                try {
                    ConformanceCheckJob::dispatch((int) $team->id, $userId, 'gp', (int) $gpId);
                    $n++;
                } catch (\Throwable $e) {
                    // Dispatch-Fehler eines GPs kippt nicht den ganzen Lauf.
                }
            });
            $this->info("── Team {$team->id} ({$team->name}): {$n} Job(s) angestoßen (von {$offen} {$status}-GP).");
            $gesamt += $n;
        }

        $this->line("→ Gesamt: {$gesamt} " . ($dry ? 'GP(s) würden geprüft (dry-run).' : 'Job(s) angestoßen — läuft im Worker ab, OFF-PEAK empfohlen.'));

        return self::SUCCESS;
    }
}
