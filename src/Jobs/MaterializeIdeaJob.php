<?php

namespace Platform\FoodAlchemist\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Platform\Core\Models\Team;
use Platform\Core\Models\User;
use Platform\FoodAlchemist\Models\FoodAlchemistDishIdea;
use Platform\FoodAlchemist\Services\FoodbookService;

/**
 * Spec 19 E7.4 — Async-Erdung EINER queued Freitext-Skizze (Kapitel-Go-Nachlauf).
 *
 * GenerateRecipeJob-Muster: der synchrone Web-/Modal-Request risse sonst den
 * fastcgi-Timeout (LLM ~25 s + GP-Grounding + Aggregation/Recompute) → 502. Die
 * database-Queue (demo-Worker 600 s) entkoppelt; der Auslöser reiht je Skizze
 * einen Job ein (FoodbookService::verarbeiteFreitextQueue).
 *
 * Auth-Restore wie GenerateRecipeJob: der im Generator genutzte AiGatewayService
 * liest Auth::user() (Kill-Switch, Food-DNA-Kaskade, Call-Log-Zuordnung) → wir
 * loggen den Auslöser wieder ein, damit die Generierung deckungsgleich zum
 * Web-Pfad läuft. Ohne User (System-Trigger) läuft der Call kontextlos, aber die
 * Erdung bleibt graceful.
 *
 * `FoodbookService::materialisiereFreitextIdee` ist SELBST graceful (kein Provider
 * → Skizze bleibt `queued`; jeder andere Fehler → `fehlgeschlagen`; nie eine
 * Exception) → der Job braucht keinen Fehler-Fallback über den Team-null-Fall
 * hinaus, und ein KI-fehlender Lauf löst KEINEN Job-Retry aus.
 */
class MaterializeIdeaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** < Worker-Timeout (600 s), > typischer Lauf (~25 s + Nachbearbeitung). */
    public int $timeout = 300;

    /** KI-Kosten: kein stiller Auto-Retry der Generierung. */
    public int $tries = 1;

    public function __construct(
        public int $teamId,
        public int $userId,
        public int $ideaId,
    ) {
    }

    public function handle(FoodbookService $foodbooks): void
    {
        $team = Team::find($this->teamId);
        if ($team === null) {
            return;
        }
        $user = $this->userId > 0 ? User::find($this->userId) : null;
        if ($user !== null) {
            Auth::login($user);   // Team-Kontext für AiGatewayService (Kill-Switch/DNA/Call-Log)
        }

        // Graceful by design — der Service verschluckt Provider-/Grounding-Fehler und
        // markiert die Skizze (queued „wartet auf KI" / fehlgeschlagen). Kein Rethrow.
        $foodbooks->materialisiereFreitextIdee($team, $this->ideaId);
    }

    /**
     * 22·H3b · V-054 (zweiter Fundort, Queue-Pfad): der Job führt keine Lauf-Zeile, aber
     * dieselbe Lüge steht bei ihm an der Skizze. Stirbt er **vor** dem Service — Timeout
     * (300 s), Fatal, Worker-Neustart, also genau die Fälle, die `handle()` nicht sieht —
     * bleibt `generation_status='queued'` stehen und die Oberfläche sagt dauerhaft
     * „wartet auf KI", obwohl niemand mehr wartet.
     *
     * Wortgleich zum eigenen `catch` des Services (`fehlgeschlagen` + `generation_fehler`),
     * damit es nicht zwei Fehl-Formen für dieselbe Sache gibt. Nur noch-queued Skizzen
     * werden angefasst: ein Lauf, der durchkam und erst danach starb, hat sein Ergebnis
     * schon geschrieben.
     */
    public function failed(?\Throwable $e): void
    {
        $idee = FoodAlchemistDishIdea::query()
            ->whereKey($this->ideaId)
            ->where('generation_status', 'queued')
            ->whereNull('materialized_at')
            ->first();
        if ($idee === null) {
            return;
        }

        $idee->update([
            'generation_status' => 'fehlgeschlagen',
            'source_meta' => array_merge($idee->source_meta ?? [], [
                'generation_fehler' => 'Job abgebrochen: ' . mb_substr($e?->getMessage() ?? 'unbekannt', 0, 500),
            ]),
        ]);
    }
}
