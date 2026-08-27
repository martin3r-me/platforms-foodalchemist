<?php

namespace Platform\FoodAlchemist\Tests\Support;

use Platform\Core\Contracts\LLMProviderContract;

/**
 * Stateful-Stub für den Konformitäts-Selbstheil-Loop.
 *
 * Der Loop ruft `conformance.check` ZWEI Mal (vor + nach der Revise-Runde) und
 * `recipe.ueberarbeiten` EIN Mal dazwischen. Der FakeAiProvider echot nur, CopilotStub
 * liefert immer dieselbe Antwort — beides trägt den Loop nicht. Hier gibt jeder
 * `conformance.check`-Call die nächste Befund-Liste aus `$conformanceRuns`;
 * `recipe.ueberarbeiten` liefert `$ueberarbeiten` (Default leer = No-Op-Revise, sodass
 * der Ausgang deterministisch am Zähler hängt, nicht am echten Revise-Effekt).
 *
 * Als PSR-4-Klasse in tests/Support/ (nicht als globale Funktion) — prozess-unabhängig
 * unter `pest --parallel`, dieselbe Regel wie {@see CopilotStub}.
 */
final class ConformanceHealStub
{
    /**
     * @param  array<int, array<int, array<string, mixed>>>  $conformanceRuns  Befunde je conformance.check-Call
     * @param  array<string, mixed>  $ueberarbeiten  werte für recipe.ueberarbeiten
     */
    public static function bind(array $conformanceRuns, array $ueberarbeiten = []): void
    {
        config(['foodalchemist.ai.provider' => 'core']);
        // singleton (NICHT bind): der Stub ist stateful (confCall-Zähler über beide
        // conformance.check-Calls des Loops) — bind gäbe pro Resolve eine frische Instanz
        // mit confCall=0, jeder Prüf-Call bekäme dann denselben ersten Run.
        app()->singleton(LLMProviderContract::class, fn () => new class($conformanceRuns, $ueberarbeiten) implements LLMProviderContract
        {
            private int $confCall = 0;

            /**
             * @param  array<int, array<int, array<string, mixed>>>  $confRuns
             * @param  array<string, mixed>  $ueber
             */
            public function __construct(private array $confRuns, private array $ueber) {}

            public function getName(): string
            {
                return 'conf-heal-stub';
            }

            public function chat(array $messages, array $options = []): array
            {
                $user = collect($messages)->where('role', 'user')->last()['content'] ?? '';

                if (str_contains($user, 'Ueberarbeite das Rezept')) {
                    $werte = $this->ueber;
                } else {
                    $werte = ['befunde' => $this->confRuns[$this->confCall] ?? [], 'gesamturteil' => 'stub'];
                    $this->confCall++;
                }

                return [
                    'content' => json_encode(['werte' => $werte, 'confidence' => 0.8, 'reasoning' => 'stub'], JSON_UNESCAPED_UNICODE),
                    'usage' => [],
                    'model' => 'stub',
                    'tool_calls' => null,
                ];
            }

            public function streamChat(array $messages, callable $onDelta, array $options = []): void
            {
                $onDelta($this->chat($messages, $options)['content']);
            }

            public function getAvailableModels(): array
            {
                return ['stub'];
            }

            public function getDefaultModel(): string
            {
                return 'stub';
            }

            public function isAvailable(): bool
            {
                return true;
            }
        });
    }
}
