<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\LLMProviderContract;
use RuntimeException;

/**
 * M0-14: KI-Gateway-Basis — Fassade vor dem Plattform-LLM (D3-Entscheid, hybrid).
 *
 * Der Transport läuft IMMER über Cores `LLMProviderContract` — kein eigener
 * HTTP-Client, kein Key-Handling im Modul. Provider-Wahl per Config:
 *     foodalchemist.ai.provider = 'core'  → Plattform-Binding (OpenAiService & Co.)
 *                               = 'fake'  → FakeAiProvider (Sandbox/Tests, ohne Key)
 *
 * M7-01: ai_call_log-Audit — jede Antwort schreibt VOR Rückgabe genau eine
 * Zeile, AUCH der Fehlerpfad (06_KI §5 Pflicht 2, try/finally erzwungen).
 * M7-02: Tiering A–D — Tier aus der Prompt-Registry (V-01), Override via
 * options['tier']; Tier→Modell-Mapping ist Deployment-Config
 * (foodalchemist.ai.tiers — null = Plattform-Default-Modell).
 *
 * Noch offen (planmäßig): Retry/Degeneration + Fence-Stripping (M7-03),
 * Voice-Hüllen via core.semantic_layer (M7-05) — Hook: $systemBlock.
 */
class AiGatewayService
{
    /**
     * GL-07 Propose: Task-Prompt + Kontext → validiertes Vorschlags-DTO.
     * Persistiert nur den AUDIT-Eintrag (06_KI §5), nie Fachdaten (GL-07 I3).
     *
     * @param array<string, mixed> $context Fachkontext — wird als JSON an die Task gehängt
     * @param array<string, mixed> $options knowledge (GL-13-Block) · knowledge_used (Audit-Slugs)
     *                                      · tier (Override) · target_table/target_id · Provider-Optionen
     */
    public function propose(string $promptKey, array $context = [], array $options = []): AiProposal
    {
        // Literaler Array-Zugriff — Prompt-Keys enthalten Punkte (config()-Dot-Notation würde sie als Pfad lesen)
        $prompt = config('foodalchemist.prompts', [])[$promptKey] ?? null;
        if (!is_array($prompt) || empty($prompt['task'])) {
            throw new RuntimeException("Unbekannter Prompt-Key [{$promptKey}] — Registry: config/foodalchemist.php → prompts.");
        }

        // M7-02: Tier aus der Registry, Override per Option; Modell aus dem Tier-Mapping
        $tier = is_string($options['tier'] ?? null) ? $options['tier'] : ($prompt['tier'] ?? 'B');
        $tierModell = config('foodalchemist.ai.tiers', [])[$tier] ?? null;

        $messages = [];
        // Hüllen-Hook (M7-05): hier kommt später der SemanticLayerResolver-Block davor
        if (!empty($prompt['system'])) {
            $messages[] = ['role' => 'system', 'content' => $prompt['system']];
        }
        // GL-13: Fakten-Wissen gehört in den USER-Prompt (Hüllen = Verhalten, additiv, nie redundant)
        $wissen = isset($options['knowledge']) && is_string($options['knowledge']) && $options['knowledge'] !== ''
            ? $options['knowledge'] . "\n\n"
            : '';
        $audit = [
            'knowledge_used' => $options['knowledge_used'] ?? null,
            'target_table' => $options['target_table'] ?? null,
            'target_id' => $options['target_id'] ?? null,
        ];
        unset($options['knowledge'], $options['knowledge_used'], $options['tier'], $options['target_table'], $options['target_id']);
        $userContent = $wissen . $prompt['task'] . "\n\nKontext:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $messages[] = ['role' => 'user', 'content' => $userContent];

        if ($tierModell !== null && ! isset($options['model'])) {
            $options['model'] = $tierModell;
        }

        // ── 06_KI §5 Pflicht 1+2: VOR Rückgabe loggen, auch im Fehlerpfad ──
        $start = hrtime(true);
        $antwort = null;
        $fehler = null;
        $parsed = null;
        try {
            $antwort = $this->provider()->chat($messages, $options + ['temperature' => $prompt['temperature'] ?? 0.1]);
            $parsed = json_decode($antwort['content'] ?? '', true);
            if (!is_array($parsed)) {
                throw new RuntimeException("KI-Antwort für [{$promptKey}] ist kein valides JSON (Fence-Stripping kommt mit M7-03).");
            }
        } catch (\Throwable $e) {
            $fehler = $e;
        }
        $elapsedMs = (int) ((hrtime(true) - $start) / 1_000_000);

        $callLogId = $this->schreibeCallLog($promptKey, $tier, $userContent, $antwort, $parsed, $fehler, $elapsedMs, $audit);

        if ($fehler !== null) {
            throw $fehler;
        }

        return new AiProposal(
            werte: $parsed['werte'] ?? [],
            confidence: min(1.0, max(0.0, (float) ($parsed['confidence'] ?? 0.0))), // Clamp (GL-07 I5)
            begruendung: $parsed['begruendung'] ?? null,
            unknownSlugs: $parsed['unknown_slugs'] ?? [],
            model: $antwort['model'] ?? null,
            elapsedMs: $elapsedMs,
            callLogId: $callLogId,
        );
    }

    /** 06_KI §5 Pflicht 3: generischer Accept-Stempel (Reject analog). */
    public function stempleAccepted(?int $callLogId): void
    {
        if ($callLogId !== null) {
            DB::table('foodalchemist_ai_call_log')->where('id', $callLogId)->update(['accepted_at' => now()]);
        }
    }

    public function stempleRejected(?int $callLogId): void
    {
        if ($callLogId !== null) {
            DB::table('foodalchemist_ai_call_log')->where('id', $callLogId)->update(['rejected_at' => now()]);
        }
    }

    private function schreibeCallLog(string $feature, string $tier, string $userContent, ?array $antwort, ?array $parsed, ?\Throwable $fehler, int $elapsedMs, array $audit): ?int
    {
        try {
            $summary = $fehler === null
                ? mb_strimwidth(json_encode($parsed['werte'] ?? [], JSON_UNESCAPED_UNICODE) ?: '', 0, 200, '…')
                : null;
            DB::table('foodalchemist_ai_call_log')->insert([
                'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
                'team_id' => Auth::user()?->currentTeamRelation?->id,
                'user_id' => Auth::id(),
                'feature' => $feature,
                'tier' => $tier,
                'model' => $antwort['model'] ?? null,
                'knowledge_used' => isset($audit['knowledge_used']) && $audit['knowledge_used'] !== null && $audit['knowledge_used'] !== []
                    ? json_encode($audit['knowledge_used']) : null,
                'prompt_hash' => hash('sha256', $userContent),
                'response_summary' => $summary,
                'tokens_in' => $antwort['usage']['input_tokens'] ?? null,
                'tokens_out' => $antwort['usage']['output_tokens'] ?? null,
                'target_table' => $audit['target_table'],
                'target_id' => $audit['target_id'],
                'error' => $fehler?->getMessage(),
                'elapsed_ms' => $elapsedMs,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return (int) DB::getPdo()->lastInsertId();
        } catch (\Throwable) {
            return null;                                             // Audit darf den Fach-Call nie reißen (graceful)
        }
    }

    public function provider(): LLMProviderContract
    {
        return match (config('foodalchemist.ai.provider', 'core')) {
            'fake' => app(FakeAiProvider::class),
            // Plattform-Binding — lazy aufgelöst, damit Sandbox/Tests ohne Core-LLM-Setup laufen
            default => app(LLMProviderContract::class),
        };
    }
}
