<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\RecipeFindingService;
use Platform\FoodAlchemist\Services\RecipeFindingsBatchService;

/**
 * Spec 21 · S5a (Tranche B) — der Batch-Lauf des L6-Copilot über den Bestand.
 *
 * Kein zweiter Prüf-Pfad: derselbe `RecipeReviewService::pruefe()`, den auch die
 * beiden Modals fahren; hier nur über eine Arbeitsmenge statt über ein Rezept, und
 * mit Ablage (`RecipeFindingService`). Die Signale daraus baut S5b — dieser Lauf
 * schreibt bewusst noch keine.
 *
 * Die Schleife selbst liegt seit 2026-07-28 in {@see RecipeFindingsBatchService}: derselbe
 * Batch ist jetzt auch über den Cockpit-Knopf und über MCP auslösbar, und ein Batch mit
 * drei Aufrufern darf nicht in einem Command wohnen. Hier bleibt nur die CLI-Haut —
 * Team-Auswahl, Pass-Validierung und die Ausgabe.
 *
 * Egress-Disziplin (der Grund für `--limit` als Default und für `ai_reviewed_at`):
 * ein Lauf prüft nur, was fällig ist (nie geprüft oder seit der Prüfung geändert),
 * und höchstens `--limit` Rezepte. Über mehrere Läufe arbeitet er den Bestand ab,
 * statt ihn jedes Mal komplett zu bezahlen.
 *
 * Nichts hier wendet etwas an: Befunde sind Befunde (GL-07). Die Übernahme bleibt
 * die menschliche Einzel-Entscheidung im Copilot-Panel.
 */
class RecipeFindingsCommand extends Command
{
    protected $signature = 'foodalchemist:recipe-findings
        {--team= : nur dieses Team (ID), sonst alle}
        {--limit=25 : höchstens so viele Rezepte je Team und Lauf (Egress-Bremse)}
        {--nur-verkauf : nur VK-Gerichte prüfen}
        {--recipe= : genau dieses Rezept prüfen (ignoriert die Fälligkeits-Auswahl)}
        {--pass=copilot : copilot = Rezeptur-Befunde (L6) | bauart = Gericht-vs-Komponente (S5b-2)}
        {--dry-run : prüfen und ausgeben, aber nichts ablegen}';

    protected $description = 'Rezept-Copilot als Batch: prüft fällige Rezepte und legt die Befunde ab (Spec 21 Tranche B).';

    public function handle(RecipeFindingsBatchService $batch): int
    {
        // S5b-2: zwei Pässe, EIN Command — sie teilen Arbeitsmengen-Logik, Lauf-
        // Bookkeeping und Egress-Bremse. Was sie NICHT teilen (Erzeuger, Prüf-Stempel,
        // Befund-Arten), liegt vollständig im RecipeFindingService.
        $pass = (string) $this->option('pass');
        if (! in_array($pass, [RecipeFindingService::PASS_COPILOT, RecipeFindingService::PASS_BAUART], true)) {
            $this->error("Unbekannter Pass [{$pass}] — erlaubt: copilot, bauart.");

            return self::FAILURE;
        }

        $teams = $this->option('team')
            ? Team::whereKey((int) $this->option('team'))->get()
            : Team::query()->get();

        if ($teams->isEmpty()) {
            $this->error('Kein Team gefunden (--team=ID prüfen).');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $trocken = (bool) $this->option('dry-run');

        foreach ($teams as $team) {
            $this->info("── Team {$team->id} ({$team->name}) · Pass {$pass}" . ($trocken ? ' · dry-run' : ''));

            $e = $batch->laufe(
                team: $team,
                limit: $limit,
                nurVerkauf: (bool) $this->option('nur-verkauf'),
                pass: $pass,
                nurRezept: $this->option('recipe') ? (int) $this->option('recipe') : null,
                trocken: $trocken,
                log: fn (string $zeile) => $this->line('   ' . $zeile),
            );

            if ($e['geprueft'] > 0 && $e['run_id'] !== null) {
                $this->line("   → offen {$e['offen']} · neu {$e['neu']} · wieder {$e['wieder']}"
                    . " · geschlossen {$e['verschwunden']} · Fehler {$e['fehler']} (Lauf {$e['run_id']})");
            }
        }

        return self::SUCCESS;
    }
}
