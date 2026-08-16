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
    ) {}

    public function handle(RecipeOneShotService $oneShot): void
    {
        $team = Team::find($this->teamId);
        $user = User::find($this->userId);
        $recipe = $team === null ? null : FoodAlchemistRecipe::visibleToTeam($team)->find($this->recipeId);
        if ($team === null || $user === null || $recipe === null) {
            $this->markEnrich('failed', 'Team/User/Rezept nicht gefunden');

            return;
        }

        Auth::login($user);   // Team-Kontext (Call-Log/Kill-Switch/DNA)
        $this->markEnrich('running');

        try {
            $oneShot->anreichern($team, $recipe, $this->zielVk, completeCoverage: true);
            $this->markEnrich('done');
        } catch (\Throwable $e) {
            // Rezept bleibt live (fail-soft) — aber der Fehler wird sichtbar (Status + Log), nicht geschluckt.
            Log::warning('[EnrichRecipeJob] Anreicherung fehlgeschlagen', ['recipe' => $this->recipeId, 'error' => $e->getMessage()]);
            $this->markEnrich('failed', $e->getMessage());
        }

        if ($this->kiBilder) {
            try {
                $res = app(RecipeImageService::class)->erzeugeFuerRezept($team, $recipe->refresh());
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

    /** Harter Job-Abbruch (nicht vom inneren try/catch abgefangen): Anreicherung als fehlgeschlagen markieren. */
    public function failed(\Throwable $e): void
    {
        $this->markEnrich('failed', $e->getMessage());
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
