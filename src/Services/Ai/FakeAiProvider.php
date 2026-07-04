<?php

namespace Platform\FoodAlchemist\Services\Ai;

use Platform\Core\Contracts\LLMProviderContract;

/**
 * M0-14: Deterministischer Fake-Provider — Sandbox/Tests ohne API-Key.
 *
 * Antwortet auf jeden chat()-Call mit gültigem Vorschlags-JSON: Er spiegelt den
 * `context`-Block aus der User-Message als `werte` zurück (Konfidenz fix 0.87).
 * Gleicher Input ⇒ gleicher Output — Golden-Test-tauglich (09 §0).
 */
class FakeAiProvider implements LLMProviderContract
{
    public function getName(): string
    {
        return 'fake';
    }

    public function chat(array $messages, array $options = []): array
    {
        $user = collect($messages)->where('role', 'user')->last()['content'] ?? '';

        // Kontext-Echo: der Gateway hängt den Kontext als JSON-Block hinter "Kontext:" an
        $werte = [];
        if (preg_match('/Kontext:\s*(\{.*\})/s', $user, $m)) {
            $werte = json_decode($m[1], true) ?? [];
        }

        return [
            'content' => json_encode([
                'werte' => $werte,
                'confidence' => 0.87,
                'reasoning' => 'FakeAiProvider: deterministisches Kontext-Echo (kein echter LLM-Call).',
            ], JSON_UNESCAPED_UNICODE),
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            'model' => 'fake-deterministic-1',
            'tool_calls' => null,
        ];
    }

    public function streamChat(array $messages, callable $onDelta, array $options = []): void
    {
        $onDelta($this->chat($messages, $options)['content']);
    }

    public function getAvailableModels(): array
    {
        return ['fake-deterministic-1'];
    }

    public function getDefaultModel(): string
    {
        return 'fake-deterministic-1';
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
