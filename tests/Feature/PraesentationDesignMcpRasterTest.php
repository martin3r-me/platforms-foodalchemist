<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Präsentation 2026-11-13 per MCP: presentation_designs.POST/PUT/GET nehmen die neuen Raster-, Überschriften-,
 * Titelbild- und Gerichtslisten-Werte an (Round-Trip) und lehnen ungültige mit VALIDATION_ERROR ab.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->tool = fn (string $name, array $args) => $this->registry->get('foodalchemist.presentation_designs.' . $name)->execute($args, $this->kontext);

    $neu = ($this->tool)('POST', ['name' => 'Raster MCP', 'base_slug' => 'editorial']);
    expect($neu->success)->toBeTrue();
    $this->designId = (int) $neu->data['id'];
});

it('PUT → GET Round-Trip: alle neuen Tokens und Block-Werte kommen unverändert zurück', function () {
    $tokens = [
        'page_width' => 'breit', 'text_width' => 'schmal', 'text_align' => 'zentriert',
        'typography' => ['heading' => 'display-serif', 'body' => 'sans', 'heading_size' => 'zurueckhaltend', 'heading_weight' => 'leicht', 'heading_tracking' => 'eng'],
    ];
    $layout = [
        ['block_type' => 'cover', 'style' => ['overlay' => 45, 'kicker_text' => 'Sommerfest · 450 Gäste']],
        ['block_type' => 'chapter_loop', 'style' => ['show_rules' => false, 'show_block_header' => false, 'show_kicker' => false]],
        ['block_type' => 'dish_list', 'style' => ['show_rules' => true, 'show_block_header' => true]],
    ];
    $put = ($this->tool)('PUT', ['id' => $this->designId, 'tokens_json' => $tokens, 'layout_json' => $layout]);
    expect($put->success)->toBeTrue();

    $get = ($this->tool)('GET', ['id' => $this->designId]);
    expect($get->data['tokens_json'])->toBe($tokens)
        ->and($get->data['layout_json'][0]['style'])->toBe(['overlay' => 45, 'kicker_text' => 'Sommerfest · 450 Gäste'])
        ->and($get->data['layout_json'][1]['style'])->toBe(['show_rules' => false, 'show_block_header' => false, 'show_kicker' => false]);
});

it('POST nimmt die neuen Werte direkt an', function () {
    $res = ($this->tool)('POST', [
        'name' => 'Raster direkt', 'tokens_json' => ['page_width' => 'schmal', 'typography' => ['heading_weight' => 'kraeftig']],
        'layout_json' => [['block_type' => 'cover', 'style' => ['overlay' => 0]]],
    ]);
    expect($res->success)->toBeTrue();
});

it('ungültige Werte → VALIDATION_ERROR mit allen Verstößen, Design bleibt unverändert', function () {
    $vorher = ($this->tool)('GET', ['id' => $this->designId])->data['tokens_json'];

    $res = ($this->tool)('PUT', ['id' => $this->designId,
        'tokens_json' => ['page_width' => 'riesig', 'text_align' => 'rechts', 'typography' => ['heading_size' => 'xxl']],
        'layout_json' => [
            ['block_type' => 'cover', 'style' => ['overlay' => 80, 'kicker_text' => str_repeat('x', 121)]],
            ['block_type' => 'chapter_loop', 'style' => ['show_rules' => 'ja']],
        ],
    ]);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('VALIDATION_ERROR')
        ->and($res->error)->toContain('tokens_json.page_width')->toContain('tokens_json.text_align')
        ->toContain('typography.heading_size')->toContain('overlay')->toContain('kicker_text')->toContain('show_rules');

    // Freitext statt Zahl (CSS-Injektionsversuch) wird ebenfalls abgelehnt.
    $css = ($this->tool)('PUT', ['id' => $this->designId, 'layout_json' => [['block_type' => 'cover', 'style' => ['overlay' => '1;background:url(x)']]]]);
    expect($css->success)->toBeFalse()->and($css->errorCode)->toBe('VALIDATION_ERROR');

    expect(($this->tool)('GET', ['id' => $this->designId])->data['tokens_json'])->toBe($vorher);
});

it('Tool-Beschreibungen nennen die neuen Keys', function () {
    foreach (['PUT', 'POST'] as $n) {
        $tool = $this->registry->get('foodalchemist.presentation_designs.' . $n);
        $text = $tool->getDescription() . json_encode($tool->getSchema(), JSON_UNESCAPED_UNICODE);
        foreach (['page_width', 'text_width', 'text_align', 'heading_size', 'heading_weight', 'heading_tracking', 'overlay', 'kicker_text', 'show_rules', 'show_block_header', 'show_kicker'] as $key) {
            expect($text)->toContain($key);
        }
    }
});
