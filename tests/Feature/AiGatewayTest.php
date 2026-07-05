<?php

use Platform\Core\Contracts\LLMProviderContract;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class);

/**
 * M0-14: KI-Gateway-Basis — Fake-Roundtrip + Provider-Wahl per Config.
 */
it('Fake-Roundtrip: propose() liefert deterministisches Vorschlags-DTO', function () {
    config(['foodalchemist.ai.provider' => 'fake']);

    $proposal = app(AiGatewayService::class)->propose('demo.echo', ['name' => 'Zanderfilet', 'condition' => 'TK']);

    expect($proposal)->toBeInstanceOf(AiProposal::class)
        ->and($proposal->werte)->toBe(['name' => 'Zanderfilet', 'condition' => 'TK']) // Kontext-Echo
        ->and($proposal->confidence)->toBe(0.87)
        ->and($proposal->model)->toBe('fake-deterministic-1')
        ->and($proposal->reasoning)->toContain('FakeAiProvider')
        ->and($proposal->callLogId)->toBeNull(); // Audit-Tabelle kommt mit M7-01
});

it('ist deterministisch: gleicher Input ⇒ gleiches Ergebnis', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    $gateway = app(AiGatewayService::class);

    $a = $gateway->propose('demo.echo', ['x' => 1]);
    $b = $gateway->propose('demo.echo', ['x' => 1]);

    expect($a->werte)->toBe($b->werte)
        ->and($a->confidence)->toBe($b->confidence);
});

it('wählt den echten Provider per Config (Transport → Core LLMProviderContract)', function () {
    // Stellvertreter für das Plattform-Binding — beweist die Weiche ohne echten Key
    $stub = new class extends FakeAiProvider {
        public function getName(): string
        {
            return 'core-stub';
        }
    };
    app()->instance(LLMProviderContract::class, $stub);

    $gateway = app(AiGatewayService::class);

    config(['foodalchemist.ai.provider' => 'core']);
    expect($gateway->provider()->getName())->toBe('core-stub');

    config(['foodalchemist.ai.provider' => 'fake']);
    expect($gateway->provider()->getName())->toBe('fake');
});

it('clampt Konfidenz auf [0,1] (GL-07 I5)', function () {
    config(['foodalchemist.ai.provider' => 'core']);
    app()->instance(LLMProviderContract::class, new class extends FakeAiProvider {
        public function chat(array $messages, array $options = []): array
        {
            return ['content' => json_encode(['werte' => [], 'confidence' => 7.5]), 'usage' => [], 'model' => 'x', 'tool_calls' => null];
        }
    });

    expect(app(AiGatewayService::class)->propose('demo.echo')->confidence)->toBe(1.0);
});

it('wirft bei unbekanntem Prompt-Key und bei Nicht-JSON-Antworten', function () {
    config(['foodalchemist.ai.provider' => 'fake']);
    $gateway = app(AiGatewayService::class);

    expect(fn () => $gateway->propose('gibt.es.nicht'))->toThrow(RuntimeException::class, 'Prompt-Key');

    config(['foodalchemist.ai.provider' => 'core']);
    app()->instance(LLMProviderContract::class, new class extends FakeAiProvider {
        public function chat(array $messages, array $options = []): array
        {
            return ['content' => 'kein json', 'usage' => [], 'model' => 'x', 'tool_calls' => null];
        }
    });
    expect(fn () => $gateway->propose('demo.echo'))->toThrow(RuntimeException::class, 'JSON');
});
