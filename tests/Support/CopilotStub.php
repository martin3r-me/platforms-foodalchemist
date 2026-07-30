<?php

namespace Platform\FoodAlchemist\Tests\Support;

use Platform\Core\Contracts\LLMProviderContract;

/**
 * Provider-Stub für die Copilot-Pässe: liefert genau die übergebene Befund-Liste
 * (der Fake-Provider echot nur den Kontext).
 *
 * Lag vorher als globale Funktion `bindCopilotStub()` IN
 * tests/Feature/RecipeReviewServiceTest.php und wurde von RecipeBauartTest mitbenutzt.
 * Sequenziell trug das, weil Pest beide Dateien in denselben Prozess lädt — unter
 * `pest --parallel` landet RecipeBauartTest in einem Worker ohne RecipeReviewServiceTest
 * und stirbt mit „Call to undefined function bindCopilotStub()". Als PSR-4-Klasse in
 * tests/Support/ ist der Helfer prozess-unabhängig (autoload-dev deckt `tests/` ab).
 *
 * Regel fürs nächste Mal: Test-Helfer, die mehr als eine Datei nutzt, gehören hierher —
 * nie als globale Funktion in eine Testdatei.
 */
final class CopilotStub
{
    public static function bind(array $befunde, string $urteil = 'Solide Basis, kleine Lücken.'): void
    {
        config(['foodalchemist.ai.provider' => 'core']);
        app()->bind(LLMProviderContract::class, fn () => new class($befunde, $urteil) implements LLMProviderContract
        {
            public function __construct(private array $befunde, private string $urteil) {}

            public function getName(): string
            {
                return 'test-stub';
            }

            public function chat(array $messages, array $options = []): array
            {
                $GLOBALS['l6_user_prompt'] = collect($messages)->where('role', 'user')->last()['content'] ?? '';

                return ['content' => json_encode(['werte' => ['befunde' => $this->befunde, 'gesamturteil' => $this->urteil],
                    'confidence' => 0.8, 'reasoning' => 'stub']), 'usage' => [], 'model' => 'stub', 'tool_calls' => null];
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
