<?php

use Platform\Core\Contracts\LLMProviderContract;
use Platform\FoodAlchemist\Services\TitelVorschlagService;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * Et.4 (Eingabe-Reife) »Titel-/Namensvorschlag aus dem Brief« — Teil 2 (Service).
 *
 * Der Service {@see TitelVorschlagService::titelVorschlag} wählt je Ebene den passenden
 * Titel-Prompt (Contract `5d3ccda`): `rezept` → `recipe.titel_vorschlag` (§1),
 * `gericht` → `vk.titel_vorschlag` (§4.4-Pipe). Concept bleibt außen vor. Brief-leer-Guard
 * + fail-soft (KI weg/Fehler → null). KI über einen selbst-enthaltenen Provider-Stub
 * (echte §-Konformität erst mit LLM-Key — Real-Abnahme auf demo).
 */

/**
 * Provider-Stub: liest den vom Gateway gebauten Prompt und antwortet ebenen-korrekt —
 * §1 (Basisrezept) vs. §4.4 (Gericht) → distinkter Titel, damit der Test die
 * Prompt-Auswahl je Scope nachweisen kann.
 */
function bindTitelStub(): void
{
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => []]);
    app()->bind(LLMProviderContract::class, fn () => new class implements LLMProviderContract
    {
        public function getName(): string
        {
            return 'titel-stub';
        }

        public function chat(array $messages, array $options = []): array
        {
            $prompt = collect($messages)->pluck('content')->filter()->implode("\n");
            // §4.4 vor §1 prüfen (der §4.4-Task nennt beide Marker nicht, aber HG-Code ist eindeutig)
            $name = str_contains($prompt, '§4.4') || str_contains($prompt, 'HG-Code')
                ? 'SUP: Tomate | Basilikum'          // Gericht (Pipe-Syntax)
                : 'Suppe: Tomate';                     // Basisrezept (§1-Syntax)

            return ['content' => json_encode(['werte' => ['name' => $name], 'confidence' => 0.8, 'reasoning' => 'stub']),
                'usage' => [], 'model' => 'stub', 'tool_calls' => null];
        }

        public function streamChat(array $messages, callable $onDelta, array $options = []): void {}

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

it('rezept-Scope liefert einen §1-Titel aus dem Brief (recipe.titel_vorschlag)', function () {
    bindTitelStub();

    $titel = app(TitelVorschlagService::class)
        ->titelVorschlag('rezept', 'Klare Tomatensuppe, sommerlich, für die Vorspeise');

    expect($titel)->toBe('Suppe: Tomate');   // §1-Syntax = Basisrezept-Prompt wurde gewählt
});

it('gericht-Scope liefert einen §4.4-Pipe-Titel aus dem Brief (vk.titel_vorschlag)', function () {
    bindTitelStub();

    $titel = app(TitelVorschlagService::class)
        ->titelVorschlag('gericht', 'Tomatensuppe mit Basilikum, als Gang im Sommermenü');

    expect($titel)->toBe('SUP: Tomate | Basilikum');   // Pipe-Syntax = Gericht-Prompt wurde gewählt
});

it('concept-Scope liefert null OHNE Provider-Call (Concept bleibt außen vor)', function () {
    // Ein werfender Provider würde jeden echten propose()-Call kippen — der Test bleibt grün,
    // weil der Scope-Guard VOR dem Gateway greift.
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => []]);
    app()->bind(LLMProviderContract::class, fn () => throw new RuntimeException('darf nicht gerufen werden'));

    expect(app(TitelVorschlagService::class)->titelVorschlag('concept', 'Sommer-Menü, leicht'))->toBeNull();
    // unbekannter Scope ebenso
    expect(app(TitelVorschlagService::class)->titelVorschlag('quatsch', 'Sommer-Menü'))->toBeNull();
});

it('leerer Brief liefert null OHNE Provider-Call (Brief-leer-Guard)', function () {
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => []]);
    app()->bind(LLMProviderContract::class, fn () => throw new RuntimeException('darf nicht gerufen werden'));

    expect(app(TitelVorschlagService::class)->titelVorschlag('rezept', '   '))->toBeNull();
    expect(app(TitelVorschlagService::class)->titelVorschlag('gericht', ''))->toBeNull();
});

it('fail-soft: ein Provider-Fehler kippt nichts, es kommt null zurück', function () {
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => []]);
    app()->bind(LLMProviderContract::class, fn () => new class implements LLMProviderContract
    {
        public function getName(): string
        {
            return 'kaputt';
        }

        public function chat(array $messages, array $options = []): array
        {
            throw new RuntimeException('Provider down');
        }

        public function streamChat(array $messages, callable $onDelta, array $options = []): void {}

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

    expect(app(TitelVorschlagService::class)->titelVorschlag('rezept', 'Tomatensuppe'))->toBeNull();
});

it('leeres/namenloses KI-Ergebnis liefert null (kein leerer Titel)', function () {
    config(['foodalchemist.ai.provider' => 'core', 'foodalchemist.ai.backoff' => []]);
    app()->bind(LLMProviderContract::class, fn () => new class implements LLMProviderContract
    {
        public function getName(): string
        {
            return 'leer';
        }

        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => ['name' => '  '], 'confidence' => 0.5, 'reasoning' => 'x']),
                'usage' => [], 'model' => 'stub', 'tool_calls' => null];
        }

        public function streamChat(array $messages, callable $onDelta, array $options = []): void {}

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

    expect(app(TitelVorschlagService::class)->titelVorschlag('gericht', 'Tomatensuppe'))->toBeNull();
});
