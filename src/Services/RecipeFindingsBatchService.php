<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\BulkRunStatus;
use Platform\FoodAlchemist\Enums\BulkRunType;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;

/**
 * Der Copilot-Batch als **eine** Stelle — herausgezogen aus
 * {@see \Platform\FoodAlchemist\Console\RecipeFindingsCommand}.
 *
 * Anlass: der Lauf war nur über artisan erreichbar, und auf demo hat ihn darum nie
 * jemand gefahren. Die Folge war nicht „ein Knopf fehlt", sondern dass die halbe
 * Copilot-Fläche leer aussah: ohne abgelegte Befunde gibt es keine
 * `rezept_plausi_ki`-Signale und auch kein „Lass das so" (das braucht eine
 * `finding_id`, s. `copilot-box.blade.php`). Jetzt fahren Command, Cockpit-Knopf und
 * MCP dieselbe Schleife.
 *
 * Bewusst **kein** zweiter Prüf-Pfad: die eigentliche Prüfung bleibt
 * {@see RecipeFindingService::pruefeUndAblegen()} — hier liegt nur die Arbeitsmengen-
 * Auswahl, die Egress-Bremse und das Lauf-Bookkeeping.
 *
 * Nichts hier wendet etwas an: Befunde sind Befunde (GL-07). Die Übernahme bleibt die
 * menschliche Einzel-Entscheidung im Copilot-Panel.
 */
class RecipeFindingsBatchService
{
    /**
     * Obergrenze je Lauf. Der Copilot ruft das Modell **pro Rezept** — bei 2.297
     * Basisrezepten ist ein Volllauf eine echte Rechnung, und ein Knopf, der sie
     * auslösen kann, braucht eine Decke, die nicht am Aufrufer hängt.
     */
    public const MAX_LIMIT = 200;

    public const DEFAULT_LIMIT = 25;

    public function __construct(
        private RecipeFindingService $findings,
        private RecipeReviewService $review,
        private RecipeBauartService $bauart,
    ) {
    }

    /**
     * Der Trockenlauf-Zweig: prüfen, ohne abzulegen. Die Pass-Weiche ist dieselbe wie in
     * `pruefeUndAblegen()`, hier nur ohne Schreiber — darum sichtbar und nicht versteckt.
     *
     * @return list<array<string,mixed>>
     */
    private function nurPruefen(Team $team, int $recipeId, string $pass): array
    {
        return $pass === RecipeFindingService::PASS_BAUART
            ? $this->bauart->pruefe($team, $recipeId)['befunde']
            : $this->review->pruefe($team, $recipeId)['befunde'];
    }

    /**
     * Ein Lauf über die fällige Arbeitsmenge eines Teams.
     *
     * @param  ?callable(string):void  $log  optionale Fortschritts-Ausgabe (CLI); der Job übergibt nichts
     * @param  ?int  $runId  bereits angelegter Lauf (Job-Pfad: die Quittung existiert schon,
     *                       bevor der Worker anläuft). null = selbst anlegen (CLI-Pfad).
     * @return array{run_id:?int,geprueft:int,offen:int,neu:int,wieder:int,verschwunden:int,fehler:int}
     */
    public function laufe(
        Team $team,
        int $limit = self::DEFAULT_LIMIT,
        bool $nurVerkauf = false,
        string $pass = RecipeFindingService::PASS_COPILOT,
        ?int $nurRezept = null,
        bool $trocken = false,
        ?callable $log = null,
        ?int $userId = null,
        ?int $runId = null,
    ): array {
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $ids = $nurRezept !== null
            ? [$nurRezept]
            : $this->findings->arbeitsmenge($team, $nurVerkauf, $pass)->limit($limit)->pluck('id')->all();

        $summe = ['neu' => 0, 'wieder' => 0, 'offen' => 0, 'verschwunden' => 0];
        $fehler = 0;

        // Den Umfang kennt erst die Arbeitsmengen-Abfrage. Ein vorab angelegter Lauf
        // (Job-Pfad) bekommt ihn hier nachgetragen; ohne ihn wird der Lauf jetzt angelegt.
        if (! $trocken) {
            $runId = $this->buchfuehrung($team, $runId, count($ids), $pass, $limit, $userId);
        } elseif ($runId === null) {
            $runId = null;
        }

        if ($ids === []) {
            $log && $log("nichts fällig ({$pass}).");

            // Auch der Leerlauf bekommt eine Quittung, sonst ist „ich habe geklickt und
            // es passierte nichts" von „der Job wurde nie eingereiht" nicht zu trennen.
            if ($runId !== null) {
                FoodAlchemistBulkRun::whereKey($runId)->update([
                    'status' => BulkRunStatus::Done->value,
                    'updated_at' => now(),
                ]);
            }

            return ['run_id' => $runId, 'geprueft' => 0, 'fehler' => 0, ...$summe];
        }

        foreach ($ids as $recipeId) {
            try {
                if ($trocken) {
                    $n = count($this->nurPruefen($team, $recipeId, $pass));
                    $log && $log("#{$recipeId}: {$n} Befund(e) (nicht abgelegt)");

                    continue;
                }
                $z = $this->findings->pruefeUndAblegen($team, $recipeId, $runId, $pass);
                foreach ($summe as $k => $_) {
                    $summe[$k] += $z[$k];
                }
                $log && $log("#{$recipeId}: {$z['offen']} offen (neu {$z['neu']}, wieder {$z['wieder']}, "
                    . "geschlossen {$z['verschwunden']})");
            } catch (\Throwable $e) {
                $fehler++;
                $log && $log("#{$recipeId}: {$e->getMessage()}");
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
        }

        return ['run_id' => $runId, 'geprueft' => count($ids), 'fehler' => $fehler, ...$summe];
    }

    /**
     * Lauf-Bookkeeping in `foodalchemist_bulk_runs` (Typ `review`) — bewusst dieselbe
     * Tabelle wie der Anreicherungs-Autopilot: „welche KI-Läufe sind gelaufen?" soll eine
     * Antwort haben, nicht zwei. Die Befunde selbst hängen ohne FK daran.
     *
     * V-047: Pass und Limit sind der Gegenstand eines Review-Laufs — zwei Läufe desselben
     * Tages unterscheiden sich sonst nur durch ihre Zeilennummer.
     */
    private function buchfuehrung(Team $team, ?int $runId, int $total, string $pass, int $limit, ?int $userId): int
    {
        // CLI-Pfad: der Umfang steht beim Anlegen fest, also darf `starte()` einen leeren
        // Lauf sofort abschließen und den Grund hinterlegen (HINWEIS_LEERE_MENGE).
        if ($runId === null) {
            return (int) FoodAlchemistBulkRun::starte(
                $team->id, BulkRunType::Review, $total, ['pass' => $pass, 'limit' => $limit], $userId
            )->id;
        }

        // Job-Pfad: der Lauf existiert schon (die Quittung ging vor dem Worker raus), der
        // Umfang wird jetzt nachgetragen. Bei leerer Menge tragen wir denselben Grund von
        // Hand nach — sonst stünde ein sofort fertiger Lauf ohne Erklärung in der Liste.
        $run = FoodAlchemistBulkRun::find($runId);
        if ($run !== null) {
            $kontext = $run->context ?? [];
            $kontext['pass'] = $pass;
            $kontext['limit'] = $limit;
            if ($total <= 0) {
                $kontext['hinweis'] = FoodAlchemistBulkRun::HINWEIS_LEERE_MENGE;
            }
            $run->update(['total' => $total, 'context' => $kontext]);
        }

        return $runId;
    }
}
