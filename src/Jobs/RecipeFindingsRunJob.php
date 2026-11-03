<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkRun;
use Platform\FoodAlchemist\Services\Ai\KiExternLauf;
use Platform\FoodAlchemist\Services\Ai\KiExternWiederholbar;
use Platform\FoodAlchemist\Services\RecipeFindingsBatchService;

/**
 * „KI-Befunde sammeln" asynchron — der Copilot-Batch über die fällige Arbeitsmenge.
 *
 * Bis hier war der Batch **nur** über artisan erreichbar, und auf demo hat ihn darum nie
 * jemand gefahren. Sichtbar war das nicht als „ein Command fehlt", sondern als halb leere
 * Oberfläche: ohne abgelegte Befunde gibt es keine `rezept_plausi_ki`-Signale, und der
 * „Lass das so"-Knopf im Copilot-Panel existiert gar nicht (er braucht eine `finding_id`).
 *
 * Dieser Lauf kostet **Provider-Geld pro Rezept** — anders als {@see QualityRunJob}. Darum
 * ist er ein eigener Knopf mit sichtbarem Limit und nicht in die Ampel-Messung gefaltet:
 * wer die Ampel neu messen will, soll nicht ungefragt eine Modell-Rechnung auslösen.
 *
 * KI dual (#1002): per MCP (`recipe_findings_run.POST`, `KiUmleitung::externFuerLauf`) läuft der Lauf extern —
 * der ganze Batch ist EIN Rumpf in der Sammel-Transaktion ({@see KiExternLauf}): jedes fällige Rezept legt
 * seinen Auftrag an (`recipe.review`/`vk.review` bzw. `recipe.bauart`), der Rumpf rollt zurück (keine Befunde,
 * kein Prüf-Stempel, Arbeitsmenge bleibt dieselbe), `ki_auftraege.ANTWORT` startet den Lauf neu, der dann die
 * Antworten verbraucht und die Befunde ablegt. UI-Knopf und Befehl bleiben Plattform-KI.
 */
class RecipeFindingsRunJob implements ShouldQueue, KiExternWiederholbar
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, \Platform\FoodAlchemist\Support\MitKiVerursacher;

    /**
     * Ein Modell-Call je Rezept, gedeckelt auf {@see RecipeFindingsBatchService::MAX_LIMIT}.
     * 3600 s wie die Anreicherungs-Läufe — dieselbe Größenordnung, dieselbe Ursache.
     */
    public int $timeout = 3600;

    /**
     * Kein Retry: ein zweiter Versuch würde die bereits bezahlten Rezepte des ersten
     * erneut prüfen. Die Egress-Bremse wäre damit ausgehebelt, und zwar unsichtbar.
     * Wer nach einem Fehlschlag weitermachen will, startet einen neuen Lauf — die
     * Fälligkeits-Auswahl (`ai_reviewed_at`) überspringt das schon Geprüfte von selbst.
     */
    public int $tries = 1;

    public function __construct(
        public int $runId,
        public int $teamId,
        public int $limit,
        public bool $nurVerkauf,
        public string $pass,
        public ?int $userId = null,
        public bool $extern = false,   // #1002: per MCP gestartet → KI-Aufträge statt Provider-Calls
        public int $versuch = 0,
    ) {
    }

    // ── #1002 · KiExternWiederholbar ──
    public function externAktiv(): bool
    {
        return $this->extern;
    }

    public function externSchluessel(): string
    {
        return "recipe-findings:{$this->teamId}:{$this->runId}";
    }

    public function externTeamId(): int
    {
        return $this->teamId;
    }

    public function externVersuch(): int
    {
        return $this->versuch;
    }

    public function externNutzlast(): array
    {
        return ['run_id' => $this->runId, 'team_id' => $this->teamId, 'limit' => $this->limit, 'nur_verkauf' => $this->nurVerkauf,
            'pass' => $this->pass, 'user_id' => $this->userId];
    }

    public static function fromExternNutzlast(array $n, int $versuch): static
    {
        return new static((int) $n['run_id'], (int) $n['team_id'], (int) $n['limit'], (bool) $n['nur_verkauf'], (string) $n['pass'],
            $n['user_id'] === null ? null : (int) $n['user_id'], true, $versuch);
    }

    public function handle(RecipeFindingsBatchService $batch): void
    {
        $stand = app(KiExternLauf::class)->fuehreAus($this, fn () => $this->arbeite($batch));
        if ($stand === KiExternLauf::WARTET) {
            // Außerhalb der zurückgerollten Transaktion: der Lauf steht sichtbar auf „wartet auf KI-Aufträge“ statt still auf running
            $run = FoodAlchemistBulkRun::find($this->runId);
            $run?->update(['context' => array_merge($run->context ?? [], ['wartet_auf' => 'ki_auftraege', 'versuch' => $this->versuch]),
                'updated_at' => now()]);
        }
    }

    /** Der Job-Rumpf — im Plattformweg unverändert, im externen Weg in der Sammel-Transaktion (KiExternLauf). */
    private function arbeite(RecipeFindingsBatchService $batch): void
    {
        $team = Team::find($this->teamId);
        if ($team === null) {
            $this->scheitern('Team ' . $this->teamId . ' existiert nicht mehr.');

            return;
        }

        $ergebnis = $batch->laufe(
            team: $team,
            limit: $this->limit,
            nurVerkauf: $this->nurVerkauf,
            pass: $this->pass,
            userId: $this->userId,
            runId: $this->runId,
        );

        // Das Ergebnis gehört an den Lauf: „was hat der Batch gefunden?" beantwortet
        // `runs.GET`, nicht der Server-Log. Status/Fortschritt setzt der Batch selbst.
        $run = FoodAlchemistBulkRun::find($this->runId);
        if ($run !== null) {
            $kontext = $run->context ?? [];
            unset($kontext['wartet_auf']);
            $run->update(['context' => array_merge($kontext, [
                'geprueft' => $ergebnis['geprueft'],
                'befunde_offen' => $ergebnis['offen'],
                'befunde_neu' => $ergebnis['neu'],
            ])]);
        }
    }

    public function failed(?\Throwable $e): void
    {
        $this->scheitern($e ?? 'Unbekannter Fehler im Befunde-Lauf.');
    }

    private function scheitern(\Throwable|string $grund): void
    {
        Log::warning('[foodalchemist] Befunde-Lauf gescheitert', [
            'run_id' => $this->runId,
            'team_id' => $this->teamId,
            'grund' => $grund instanceof \Throwable ? $grund->getMessage() : $grund,
        ]);

        FoodAlchemistBulkRun::markiereGescheitert($this->runId, $grund);
    }
}
