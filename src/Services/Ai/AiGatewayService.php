<?php

namespace Platform\FoodAlchemist\Services\Ai;

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
 * Bewusst NOCH NICHT hier (kommt planmäßig in M7):
 *   - ai_call_log-Audit (M7-01) → AiProposal::callLogId bleibt NULL
 *   - Tiering A–D (M7-02), Retry/Degeneration (M7-03), Fence-Stripping (M7-03)
 *   - Voice-Hüllen via core.semantic_layer (M7-05, GL-06 §6) — Hook: $systemBlock
 */
class AiGatewayService
{
    /**
     * GL-07 Propose: Task-Prompt + Kontext → validiertes Vorschlags-DTO.
     * Persistiert NICHTS (GL-07 I3).
     *
     * @param array<string, mixed> $context Fachkontext — wird als JSON an die Task gehängt
     */
    public function propose(string $promptKey, array $context = [], array $options = []): AiProposal
    {
        // Literaler Array-Zugriff — Prompt-Keys enthalten Punkte (config()-Dot-Notation würde sie als Pfad lesen)
        $prompt = config('foodalchemist.prompts', [])[$promptKey] ?? null;
        if (!is_array($prompt) || empty($prompt['task'])) {
            throw new RuntimeException("Unbekannter Prompt-Key [{$promptKey}] — Registry: config/foodalchemist.php → prompts.");
        }

        $messages = [];
        // Hüllen-Hook (M7-05): hier kommt später der SemanticLayerResolver-Block davor
        if (!empty($prompt['system'])) {
            $messages[] = ['role' => 'system', 'content' => $prompt['system']];
        }
        // GL-13: Fakten-Wissen gehört in den USER-Prompt (Hüllen = Verhalten, additiv, nie redundant)
        $wissen = isset($options['knowledge']) && is_string($options['knowledge']) && $options['knowledge'] !== ''
            ? $options['knowledge'] . "\n\n"
            : '';
        unset($options['knowledge']);
        $messages[] = [
            'role' => 'user',
            'content' => $wissen . $prompt['task'] . "\n\nKontext:\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ];

        $start = hrtime(true);
        $antwort = $this->provider()->chat($messages, $options + ['temperature' => $prompt['temperature'] ?? 0.1]);
        $elapsedMs = (int) ((hrtime(true) - $start) / 1_000_000);

        $parsed = json_decode($antwort['content'] ?? '', true);
        if (!is_array($parsed)) {
            throw new RuntimeException("KI-Antwort für [{$promptKey}] ist kein valides JSON (Fence-Stripping kommt mit M7-03).");
        }

        return new AiProposal(
            werte: $parsed['werte'] ?? [],
            confidence: min(1.0, max(0.0, (float) ($parsed['confidence'] ?? 0.0))), // Clamp (GL-07 I5)
            begruendung: $parsed['begruendung'] ?? null,
            unknownSlugs: $parsed['unknown_slugs'] ?? [],
            model: $antwort['model'] ?? null,
            elapsedMs: $elapsedMs,
        );
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
