<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;
use Platform\FoodAlchemist\Services\RecipeBauartService;
use Platform\FoodAlchemist\Services\RecipeFindingService;
use Platform\FoodAlchemist\Services\RecipeReviewService;

/**
 * Spec 21 · S5a (Tranche B) — der Batch-Lauf des L6-Copilot über den Bestand.
 *
 * Kein zweiter Prüf-Pfad: derselbe `RecipeReviewService::pruefe()`, den auch die
 * beiden Modals fahren; hier nur über eine Arbeitsmenge statt über ein Rezept, und
 * mit Ablage (`RecipeFindingService`). Die Signale daraus baut S5b — dieser Lauf
 * schreibt bewusst noch keine.
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

    public function handle(RecipeFindingService $findings, RecipeReviewService $review): int
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
            $ids = $this->option('recipe')
                ? [(int) $this->option('recipe')]
                : $findings->arbeitsmenge($team, (bool) $this->option('nur-verkauf'), $pass)->limit($limit)->pluck('id')->all();

            if ($ids === []) {
                $this->line("── Team {$team->id} ({$team->name}): nichts fällig ({$pass}).");

                continue;
            }

            $this->info("── Team {$team->id} ({$team->name}): " . count($ids) . " fällige(s) Rezept(e) · Pass {$pass}"
                . ($trocken ? ' · dry-run' : ''));

            $runId = $trocken ? null : $this->starteRun($team->id, count($ids), $pass, $limit);
            $summe = ['neu' => 0, 'wieder' => 0, 'offen' => 0, 'verschwunden' => 0];
            $fehler = 0;

            foreach ($ids as $recipeId) {
                try {
                    if ($trocken) {
                        $n = count($pass === RecipeFindingService::PASS_BAUART
                            ? app(RecipeBauartService::class)->pruefe($team, $recipeId)['befunde']
                            : $review->pruefe($team, $recipeId)['befunde']);
                        $this->line("   #{$recipeId}: {$n} Befund(e) (nicht abgelegt)");

                        continue;
                    }
                    $z = $findings->pruefeUndAblegen($team, $recipeId, $runId, $pass);
                    foreach ($summe as $k => $_) {
                        $summe[$k] += $z[$k];
                    }
                    $this->line("   #{$recipeId}: {$z['offen']} offen (neu {$z['neu']}, wieder {$z['wieder']}, "
                        . "geschlossen {$z['verschwunden']})");
                } catch (\Throwable $e) {
                    $fehler++;
                    $this->warn("   #{$recipeId}: {$e->getMessage()}");
                } finally {
                    // Fortschritt zählt auch das gescheiterte Rezept mit (`done` ist
                    // „abgearbeitet", nicht „gelungen"); `failed` wird am Lauf-Ende gesetzt.
                    if ($runId !== null) {
                        FoodAlchemistBulkRun::whereKey($runId)->update([
                            'done' => DB::raw('done + 1'), 'updated_at' => now(),
                        ]);
                    }
                }
            }

            if ($runId !== null) {
                FoodAlchemistBulkRun::whereKey($runId)
                    ->update(['status' => BulkRunStatus::Done->value, 'failed' => $fehler, 'updated_at' => now()]);
                $this->line("   → offen {$summe['offen']} · neu {$summe['neu']} · wieder {$summe['wieder']}"
                    . " · geschlossen {$summe['verschwunden']} · Fehler {$fehler} (Lauf {$runId})");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Lauf-Bookkeeping in `foodalchemist_bulk_runs` (Typ `review`) — bewusst dieselbe
     * Tabelle wie der Anreicherungs-Autopilot: „welche KI-Läufe sind gelaufen?" soll
     * eine Antwort haben, nicht zwei. Die Befunde selbst hängen ohne FK daran.
     */
    private function starteRun(int $teamId, int $total, string $pass, ?int $limit): int
    {
        // V-047: Pass und Limit sind der Gegenstand eines Review-Laufs — zwei Läufe
        // desselben Tages unterscheiden sich sonst nur durch ihre Zeilennummer.
        return (int) FoodAlchemistBulkRun::starte($teamId, BulkRunType::Review, $total, [
            'pass' => $pass,
            'limit' => $limit,
        ])->id;
    }
}
