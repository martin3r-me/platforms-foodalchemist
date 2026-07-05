<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M7-03 / 06_KI §3: Fence-Stripping (§3.4.2, Ist-Testfälle), Degenerations-
 * Re-Roll (§3.3, Temp 0.3→0.5→0.7), Structural-Retry (Generator-Variante),
 * Backoff + einmaliger Modell-Fallback (§3.1/3.2). DoD: kaputte Fake-Antwort
 * → Retry → Erfolg.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);  // Tests: keine sleeps
    $this->gw = app(AiGatewayService::class);

    // Provider-Stub mit Antwort-Skript (Versuch n → Antwort n) — singleton,
    // damit der Versuchs-Zähler über die Retry-Schleife hinweg lebt
    $this->skriptProvider = function (array $antworten) {
        app()->singleton(FakeAiProvider::class, fn () => new class($antworten) extends FakeAiProvider
        {
            private int $i = 0;

            public function __construct(private array $antworten)
            {
            }

            public function chat(array $messages, array $options = []): array
            {
                $a = $this->antworten[min($this->i++, count($this->antworten) - 1)];
                if ($a instanceof \Throwable) {
                    throw $a;
                }

                return ['content' => $a, 'model' => 'fake-skript', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];
            }
        });
    };
});

it('Fence-Stripping: Ist-Testfälle (gemini.rs:788-859) als Datasets', function (string $raw, string $erwartet) {
    expect($this->gw->stripJsonFence($raw))->toBe($erwartet);
})->with([
    'plain' => ['{"a":1}', '{"a":1}'],
    'markdown-fence' => ["```json\n{\"a\":1}\n```", '{"a":1}'],
    'prosa davor + müll danach' => ['Hier dein JSON: {"a":1} Viel Spaß!', '{"a":1}'],
    'array-wert' => ['vorab [1,2,{"b":3}] nachgelagert', '[1,2,{"b":3}]'],
    'klammern in string-literal' => ['{"text":"ein } in der Mitte"} rest', '{"text":"ein } in der Mitte"}'],
    'escapte quotes' => ['{"text":"sagte \"hi\" {x}"}{}', '{"text":"sagte \"hi\" {x}"}'],
    'unbalanciert = ehrliche truncation' => ['{"a": [1, 2', '{"a": [1, 2'],
    'gar kein json' => ['nur Prosa ohne Klammern', 'nur Prosa ohne Klammern'],
]);

it('DoD §3.3: kaputte Antwort (Degeneration) → Re-Roll mit Temp-Treppe → Erfolg auf Versuch 2', function () {
    ($this->skriptProvider)([
        '{"werte": {"x": 1}',                                         // Versuch 1: truncated → Parse-Fehler
        '{"werte": {"x": 2}, "confidence": 0.9}',                     // Versuch 2: valide
    ]);

    $p = $this->gw->propose('recipe.description', ['b' => 1]);

    expect($p->werte)->toBe(['x' => 2])
        ->and($p->confidence)->toBe(0.9)
        ->and(DB::table('foodalchemist_ai_call_log')->orderByDesc('id')->value('error'))->toBeNull();
});

it('§3.3 Generator-Variante: strukturell unbrauchbar (leere zutaten) → Retry → brauchbar', function () {
    ($this->skriptProvider)([
        '{"werte": {"name": "X", "zutaten": []}}',                    // valide, aber unbrauchbar
        '{"werte": {"name": "X", "zutaten": [{"text": "Salz"}]}, "confidence": 0.8}',
    ]);

    $p = $this->gw->propose('recipe.generator', [], [
        'structural_retry' => fn (array $parsed) => ! empty($parsed['werte']['zutaten']),
    ]);

    expect($p->werte['zutaten'])->toHaveCount(1);
});

it('§3.3: 3× kaputt → Exception NACH dem Log (error-Zeile, Versuch 3 dokumentiert)', function () {
    ($this->skriptProvider)(['MÜLL', 'MÜLL', 'MÜLL']);

    expect(fn () => $this->gw->propose('recipe.description', ['b' => 1]))
        ->toThrow(RuntimeException::class, 'Versuch 3');

    expect(DB::table('foodalchemist_ai_call_log')->orderByDesc('id')->value('error'))->toContain('Versuch 3');
});

it('§3.2: nach erschöpftem Backoff einmaliger Modell-Fallback — model trägt das echte Modell', function () {
    config(['foodalchemist.ai.fallback_model' => 'billig-fallback']);
    app()->bind(FakeAiProvider::class, fn () => new class extends FakeAiProvider
    {
        public function chat(array $messages, array $options = []): array
        {
            if (($options['model'] ?? null) !== 'billig-fallback') {
                throw new RuntimeException('HTTP 503');               // Primär-Modell down
            }

            return ['content' => '{"werte": {"ok": true}, "confidence": 0.7}', 'model' => 'billig-fallback', 'usage' => []];
        }
    });

    $p = $this->gw->propose('recipe.description', ['b' => 1]);

    expect($p->werte)->toBe(['ok' => true])
        ->and($p->model)->toBe('billig-fallback')
        ->and(DB::table('foodalchemist_ai_call_log')->orderByDesc('id')->value('model'))->toBe('billig-fallback');
});
