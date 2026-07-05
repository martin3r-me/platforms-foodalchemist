<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\SemanticLayer\DTOs\ResolvedLayer;
use Platform\Core\SemanticLayer\Services\SemanticLayerResolver;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\FakeAiProvider;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M7-05 / GL-06 §6: Voice-Hülle aus core.semantic_layer als ERSTE
 * systemInstruction; layers_used (Inv. 7) im Audit. DoD: Layer-Wechsel
 * ändert den Prompt nachweisbar. Graceful ohne Layer/Resolver.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);

    // Provider-Spy: captured die messages des letzten Calls
    $this->spy = new class extends FakeAiProvider
    {
        public array $messages = [];

        public function chat(array $messages, array $options = []): array
        {
            $this->messages = $messages;

            return ['content' => '{"werte": {"ok": 1}, "confidence": 0.9}', 'model' => 'fake-spy', 'usage' => []];
        }
    };
    app()->instance(FakeAiProvider::class, $this->spy);

    $this->bindLayer = function (?string $block, array $chain = []) {
        app()->instance(SemanticLayerResolver::class, new class($block, $chain) extends SemanticLayerResolver
        {
            public function __construct(private ?string $block, private array $chain)
            {
            }

            public function resolveFor($team, $module): ResolvedLayer
            {
                return $this->block === null ? ResolvedLayer::empty() : new ResolvedLayer(
                    perspektive: 'souschef', ton: ['praezise'], heuristiken: [], negativ_raum: [],
                    scope_chain: ['global'], version_chain: $this->chain, token_count: 10,
                    rendered_block: $this->block,
                );
            }
        });
    };
});

it('DoD: Layer-Wechsel ändert den Prompt nachweisbar (erste systemInstruction)', function () {
    ($this->bindLayer)('HÜLLE ALPHA: Antworte als Catering-Souschef.', [['key' => 'global.default', 'semver' => '1.0.0']]);
    app(AiGatewayService::class)->propose('recipe.description', ['b' => 1]);
    $alpha = $this->spy->messages;

    ($this->bindLayer)('HÜLLE BETA: Antworte knapp und norddeutsch.', [['key' => 'team.kueste', 'semver' => '2.1.0']]);
    app(AiGatewayService::class)->propose('recipe.description', ['b' => 1]);
    $beta = $this->spy->messages;

    expect($alpha[0]['role'])->toBe('system')
        ->and($alpha[0]['content'])->toContain('HÜLLE ALPHA')
        ->and($beta[0]['content'])->toContain('HÜLLE BETA')
        ->and($alpha[0]['content'])->not->toBe($beta[0]['content']);  // Wechsel nachweisbar
});

it('layers_used (GL-06 Inv. 7) landet im Audit; ohne Layer bleibt es null', function () {
    ($this->bindLayer)('HÜLLE', [['key' => 'global.default', 'semver' => '1.0.0']]);
    app(AiGatewayService::class)->propose('recipe.description', ['b' => 1]);
    expect(json_decode(DB::table('foodalchemist_ai_call_log')->orderByDesc('id')->value('layers_used'), true))
        ->toBe([['key' => 'global.default', 'semver' => '1.0.0']]);

    ($this->bindLayer)(null);                                         // kein Layer → empty
    app(AiGatewayService::class)->propose('recipe.description', ['b' => 1]);
    expect(DB::table('foodalchemist_ai_call_log')->orderByDesc('id')->value('layers_used'))->toBeNull()
        ->and($this->spy->messages[0]['role'])->toBe('user');         // keine system-Message vorangestellt
});

it('Hülle ist abschaltbar und graceful (Config aus → kein Resolver-Aufruf)', function () {
    config(['foodalchemist.ai.huellen' => false]);
    ($this->bindLayer)('HÜLLE DARF NICHT ERSCHEINEN');

    app(AiGatewayService::class)->propose('recipe.description', ['b' => 1]);

    expect(collect($this->spy->messages)->pluck('content')->implode(' '))->not->toContain('DARF NICHT');
});
