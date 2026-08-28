<?php

use Platform\FoodAlchemist\Services\PresentationDesignService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 43 Stufe 2 („Leinwand via Code") — KI/User-Custom-CSS am Design: Sanitizer (kein
 * </style>-Breakout, kein @import/expression), Snapshot-Freeze, Anwendung im Public-Render.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->designs = app(PresentationDesignService::class);
    $this->pres = app(PresentationService::class);
    $this->actingAs($this->makeUser($this->rootTeam, 'Designer'));

    $this->baueFb = function ($team) {
        $fb = $this->makeFoodbook($team, 'CSS-Katalog', ['personen' => 4]);
        $kap = $this->makeChapter($fb, ['title' => 'Start', 'consumer_title' => 'Start', 'position' => 1]);
        $dish = $this->makeRecipe($team, 'Suppe', ['is_sales_recipe' => true, 'sales_net' => 5.0]);
        $this->makeFoodbookBlock($kap, ['type' => 'recipe_ref', 'sales_recipe_id' => $dish->id, 'position' => 1]);

        return $fb;
    };
});

it('sanitizeCss entfernt Breakout/@import/expression, behält gültiges CSS', function () {
    $raw = ".pt-title{color:#f0f} </style><script>alert(1)</script> @import url('//evil'); a{behavior:url(x)} b{background:expression(alert(1))}";
    $clean = $this->designs->sanitizeCss($raw);

    expect($clean)->toContain('.pt-title{color:#f0f}')
        ->and($clean)->not->toContain('<')
        ->and($clean)->not->toContain('</style')
        ->and($clean)->not->toContain('<script')
        ->and(strtolower($clean))->not->toContain('@import')
        ->and(strtolower($clean))->not->toContain('expression(')
        ->and(strtolower($clean))->not->toContain('behavior:');
});

it('Design-Custom-CSS wird eingefroren + im Public-Render angewandt, ohne Script-Injektion', function () {
    $team = $this->rootTeam;
    $design = $this->designs->create($team, [
        'name' => 'Mein Look',
        'base_slug' => 'editorial',
        'custom_css' => '.pt-hero-title{ letter-spacing: 0.5em; } </style><script>alert(9)</script>',
    ]);
    $fb = ($this->baueFb)($team);

    $res = $this->pres->publish($team, 'foodbook', $fb->id, [
        'design' => 'design:' . $design->id,
        'expires_at' => now()->addDays(30)->toDateString(),
    ]);

    // Im Snapshot eingefroren + sanitisiert.
    $snap = $fb->refresh()->presentation_snapshot_json;
    expect($snap['resolved_design']['custom_css'])->toContain('letter-spacing: 0.5em')
        ->and($snap['resolved_design']['custom_css'])->not->toContain('<script');

    // Public-Render: CSS-Regel da, Script NICHT.
    $this->get('/p/foodbook/' . $res['token'])
        ->assertOk()
        ->assertSee('letter-spacing: 0.5em', false)
        ->assertDontSee('<script>alert(9)', false);
});

it('Struktur-Builder speichert custom_css + Live-Vorschau wendet es an', function () {
    $team = $this->rootTeam;
    $fb = ($this->baueFb)($team);

    $c = Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Settings\PraesentationsDesigns::class)
        ->set('name', 'Builder-CSS')
        ->set('baseSlug', 'editorial')
        ->set('customCss', '.pt-hero-title{ letter-spacing:.09em }')
        ->set('previewFoodbookId', $fb->id)
        ->call('speichern');

    $id = $c->get('selectedId');
    $design = $this->designs->find($team, $id);
    expect($design->custom_css)->toContain('letter-spacing:.09em');

    // Live-Vorschau (designPreview) trägt das CSS.
    $snap = $this->pres->designPreview($team, $fb->id, $design->layout_json, $design->tokens_json, $design->custom_css);
    expect($snap['resolved_design']['custom_css'])->toContain('letter-spacing:.09em');
});

it('CSS-Edit nach Freigabe ändert den Public-Link nicht (Snapshot stabil)', function () {
    $team = $this->rootTeam;
    $design = $this->designs->create($team, ['name' => 'L', 'base_slug' => 'editorial', 'custom_css' => '.x{color:#111}']);
    $fb = ($this->baueFb)($team);
    $res = $this->pres->publish($team, 'foodbook', $fb->id, ['design' => 'design:' . $design->id, 'expires_at' => now()->addDays(30)->toDateString()]);

    $this->designs->update($team, $design->id, ['custom_css' => '.x{color:#999}']);

    $snap = $this->pres->resolveByToken('foodbook', $res['token']);
    expect($snap['resolved_design']['custom_css'])->toContain('#111')->not->toContain('#999');
});
