<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\RecipeImageService;
use Platform\FoodAlchemist\Services\RecipeOneShotService;

/**
 * Freigabe-Schritt der gestuften Kaskade: reichert ein freigegebenes Draft KOMPLETT an
 * ({@see RecipeOneShotService::anreichern}, completeCoverage) und erzeugt — falls beim Go der
 * KI-Bilder-Toggle (`ki_bilder`) an war — Schritt-für-Schritt-Fotos + ein Produktfoto
 * ({@see RecipeImageService}).
 *
 * Fail-soft für das REZEPT (das freigegebene Rezept bleibt live, egal was kippt) — aber NICHT
 * mehr stumm: das Ergebnis wird pro Step in `deferred.enrich` festgehalten (queued→running→
 * done|failed) und jeder Fehler geloggt, damit „läuft durch" nicht über einen rohen Entwurf lügt.
 * Die Planung pollt `deferred.enrich` und zeigt Status + „neu anreichern".
 *
 * Fehler-Transparenz auch beim HARTEN Abbruch (Timeout/OOM, nicht vom inneren try/catch gefangen):
 * {@see self::failed} ordnet den Fehler der richtigen Phase zu — kippte der Job erst nach dem
 * abgeschlossenen `enrich=done`, gehört er an `deferred.bilder` (Bild-Phase), nicht ans
 * überschriebene enrich.
 *
 * Etappe 7, Teil 2b — »nur Bilder«-Modus (`$nurBilder`): re-triggert AUSSCHLIESSLICH die KI-Fotos
 * (ohne Voll-Anreicherung), z. B. nach `deferred.bilder=failed` über den Cockpit-Knopf „neu erzeugen"
 * ({@see \Platform\FoodAlchemist\Services\PlanningCascadeService::reBilder}). Ersetzt die alten
 * KI-Fotos statt sie anzuhäufen (manuelle Uploads bleiben) und lässt `deferred.enrich` unangetastet.
 */
class EnrichRecipeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(
        public int $teamId,
        public int $userId,
        public int $recipeId,
        public ?float $zielVk = null,
        public bool $kiBilder = false,
        public ?int $stepId = null,
        public bool $nurBilder = false,
        public bool $refresh = false,   // #4: Kaskaden-Anreicherung aus dem Editor → auch gefüllte, nicht-manuelle Felder auffrischen
        public bool $completeCoverage = true,   // Phase 0.3: Anreicherungs-Tiefe (Step-by-Step/Sensorik/…); Default an = Bestandsverhalten
    ) {}

    public function handle(RecipeOneShotService $oneShot): void
    {
        $team = Team::find($this->teamId);
        $user = User::find($this->userId);
        $recipe = $team === null ? null : FoodAlchemistRecipe::visibleToTeam($team)->find($this->recipeId);
        if ($team === null || $user === null || $recipe === null) {
            // Im »nur Bilder«-Modus lebt der Status in deferred.bilder — die Anreicherung war schon durch.
            $this->nurBilder
                ? $this->markBilder('failed', 'Team/User/Rezept nicht gefunden', 0)
                : $this->markEnrich('failed', 'Team/User/Rezept nicht gefunden');

            return;
        }

        Auth::login($user);   // Team-Kontext (Call-Log/Kill-Switch/DNA)

        // Voll-Anreicherung nur im Normalpfad — »nur Bilder« (Teil 2b) lässt das angereicherte
        // Rezept + deferred.enrich unangetastet und erzeugt ausschliesslich die Fotos neu.
        if (! $this->nurBilder) {
            $this->markEnrich('running');
            try {
                $oneShot->anreichern($team, $recipe, $this->zielVk, completeCoverage: $this->completeCoverage, refresh: $this->refresh);
                // GP-Mint (EK-Vollständigkeit) ist orthogonal zur Text-Coverage: `anreichern` mintet nur bei
                // completeCoverage. Bei „leichter" Anreicherung (Step-by-Step aus) trotzdem minten, damit die
                // Kalkulation vollständig bleibt (fail-soft, mintet nur echte Roh-Lücken, s. minteFehlendeGps).
                if (! $this->completeCoverage) {
                    $oneShot->minteFehlendeGps($team, $recipe->fresh() ?? $recipe);
                }
                $this->markEnrich('done');
            } catch (\Throwable $e) {
                // Rezept bleibt live (fail-soft) — aber der Fehler wird sichtbar (Status + Log), nicht geschluckt.
                Log::warning('[EnrichRecipeJob] Anreicherung fehlgeschlagen', ['recipe' => $this->recipeId, 'error' => $e->getMessage()]);
                $this->markEnrich('failed', $e->getMessage());
            }
        }

        if ($this->kiBilder || $this->nurBilder) {
            try {
                $imageService = app(RecipeImageService::class);
                if ($this->nurBilder) {
                    // „Neu erzeugen": vorhandene KI-Fotos ERSETZEN (nicht anhäufen) — manuelle Uploads bleiben.
                    $imageService->loescheKiFotos($team, $recipe->refresh());
                }
                $res = $imageService->erzeugeFuerRezept($team, $recipe->refresh());
                // Ehrlicher Bild-Status: ein einzelner fehlgeschlagener Call (Produkt- oder Schritt-Foto)
                // macht die Erzeugung als Ganzes »failed« (Teil-Erfolge trägt `n`), sonst »done«.
                $this->markBilder(
                    ((int) ($res['fehler'] ?? 0)) > 0 ? 'failed' : 'done',
                    $res['letzter_fehler'] ?? null,
                    (int) ($res['erzeugt'] ?? 0),
                );
            } catch (\Throwable $e) {
                // KI-Fotos sind optional (Preisfrage) — Fehler kippen die Freigabe/Anreicherung nie, aber werden
                // geloggt UND als Bild-Status festgehalten (Cockpit-Badge »fehlgeschlagen« statt stummem 0-Foto).
                Log::warning('[EnrichRecipeJob] KI-Fotos fehlgeschlagen', ['recipe' => $this->recipeId, 'error' => $e->getMessage()]);
                $this->markBilder('failed', $e->getMessage(), 0);
            }
        }
    }

    /** Harter Job-Abbruch (nicht vom inneren try/catch abgefangen, z. B. Timeout/OOM): als
     *  fehlgeschlagen markieren — und den Fehler der RICHTIGEN Phase zuordnen (Fehler-Transparenz). */
    public function failed(\Throwable $e): void
    {
        // Im »nur Bilder«-Modus gehört der Fehler an deferred.bilder (die Anreicherung war längst durch).
        if ($this->nurBilder) {
            $this->markBilder('failed', $e->getMessage(), 0);

            return;
        }

        // Voll-Modus: kippte der Job HART erst NACH der abgeschlossenen Anreicherung (enrich=done),
        // kann der Abbruch nur in der Bild-Phase passiert sein — das Einzige, was nach
        // markEnrich('done') noch läuft. Dann den Fehler an deferred.bilder hängen, statt das korrekte
        // enrich=done zu ÜBERSCHREIBEN und die Bild-Panne der Anreicherung unterzuschieben: das
        // Cockpit-Badge zeigt so „Fotos fehlgeschlagen", nicht fälschlich „Anreicherung fehlgeschlagen".
        if ($this->kiBilder && $this->enrichAbgeschlossen()) {
            $this->markBilder('failed', $e->getMessage(), 0);

            return;
        }

        $this->markEnrich('failed', $e->getMessage());
    }

    /**
     * Ist die Voll-Anreicherung am Step bereits als `done` vermerkt? Für die Fehler-Zuordnung in
     * {@see self::failed}: ein harter Abbruch danach betrifft nur noch die Bild-Phase, nicht die
     * (erfolgreiche) Anreicherung. Fehlender Step / kein Vermerk / laufende Anreicherung → false
     * (Abbruch der Anreicherung selbst → deferred.enrich). Lesen ist Beiwerk, nie blockierend.
     */
    private function enrichAbgeschlossen(): bool
    {
        if ($this->stepId === null) {
            return false;
        }
        try {
            $step = FoodAlchemistCascadeRunStep::find($this->stepId);

            return is_array($step?->deferred)
                && (($step->deferred['enrich']['status'] ?? null) === 'done');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Anreicherungs-Status am auslösenden Kaskaden-Step festhalten (`deferred.enrich`).
     * Beiwerk — ein Tracking-Fehler darf die Anreicherung nie kippen.
     */
    private function markEnrich(string $status, ?string $error = null): void
    {
        if ($this->stepId === null) {
            return;
        }
        try {
            $step = FoodAlchemistCascadeRunStep::find($this->stepId);
            if ($step === null) {
                return;
            }
            $deferred = is_array($step->deferred) ? $step->deferred : [];
            $deferred['enrich'] = array_filter([
                'status' => $status,
                'error' => $error !== null ? Str::limit($error, 200) : null,
                'at' => now()->toIso8601String(),
            ], fn ($v) => $v !== null);
            $step->update(['deferred' => $deferred]);
        } catch (\Throwable) {
            // Tracking ist Beiwerk — nie blockierend.
        }
    }

    /**
     * Bild-Erzeugungs-Status am auslösenden Kaskaden-Step festhalten (`deferred.bilder`) — analog
     * {@see self::markEnrich}. Macht den bisher stummen fail-soft-Zustand der KI-Foto-Erzeugung
     * sichtbar (Cockpit-Badge »N Fotos ✓« / »fehlgeschlagen«). Beiwerk — ein Tracking-Fehler darf
     * die Anreicherung/Freigabe nie kippen.
     */
    private function markBilder(string $status, ?string $error = null, int $n = 0): void
    {
        if ($this->stepId === null) {
            return;
        }
        try {
            $step = FoodAlchemistCascadeRunStep::find($this->stepId);
            if ($step === null) {
                return;
            }
            $deferred = is_array($step->deferred) ? $step->deferred : [];
            $deferred['bilder'] = array_filter([
                'status' => $status,
                'n' => $n,
                'error' => $error !== null ? Str::limit($error, 200) : null,
                'at' => now()->toIso8601String(),
            ], fn ($v) => $v !== null);
            $step->update(['deferred' => $deferred]);
        } catch (\Throwable) {
            // Tracking ist Beiwerk — nie blockierend.
        }
    }
}
