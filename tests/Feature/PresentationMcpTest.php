<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 43 — MCP-Lockstep: foodbook_presentation.{PUBLISH,WITHDRAW,GET} + presentation_designs.{CRUD}.
 * Round-Trips, Tenancy (NOT_FOUND), Pflicht-Datum (VALIDATION_ERROR), Registry-Smoke.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);

    $this->baue = function ($team) {
        $fb = $this->makeFoodbook($team, 'MCP-Katalog', ['personen' => 5]);
        $kap = $this->makeChapter($fb, ['title' => 'Vorspeisen', 'consumer_title' => 'Vorspeisen', 'position' => 1]);
        $dish = $this->makeRecipe($team, 'Suppe', ['is_sales_recipe' => true, 'sales_net' => 5.0]);
        $this->makeFoodbookBlock($kap, ['type' => 'recipe_ref', 'sales_recipe_id' => $dish->id, 'position' => 1]);

        return $fb;
    };
});

it('Registry-Smoke: alle Spec-43-Tools registriert (object-Schema)', function () {
    foreach ([
        'foodalchemist.foodbook_presentation.PUBLISH', 'foodalchemist.foodbook_presentation.WITHDRAW', 'foodalchemist.foodbook_presentation.GET',
        'foodalchemist.presentation_designs.POST', 'foodalchemist.presentation_designs.PUT', 'foodalchemist.presentation_designs.GET',
        'foodalchemist.presentation_designs.SEARCH', 'foodalchemist.presentation_designs.DELETE',
    ] as $name) {
        expect($this->registry->get($name))->not->toBeNull($name)
            ->and($this->registry->get($name)->getSchema()['type'] ?? null)->toBe('object', $name);
    }
});

it('PUBLISH → GET Round-Trip + Public-Route erreichbar; WITHDRAW → GET inaktiv + 404', function () {
    $fb = ($this->baue)($this->rootTeam);

    $pub = $this->registry->get('foodalchemist.foodbook_presentation.PUBLISH')->execute([
        'foodbook_id' => $fb->id, 'expires_at' => now()->addDays(30)->toDateString(), 'design' => 'menu',
    ], $this->kontext);
    expect($pub->success)->toBeTrue()
        ->and($pub->data['token'])->not->toBeEmpty()
        ->and($pub->data['design'])->toBe('menu');

    $get = $this->registry->get('foodalchemist.foodbook_presentation.GET')->execute(['foodbook_id' => $fb->id], $this->kontext);
    expect($get->data['enabled'])->toBeTrue()->and($get->data['live'])->toBeTrue();

    $this->get('/p/foodbook/' . $pub->data['token'])->assertOk()->assertSee('MCP-Katalog');

    $wd = $this->registry->get('foodalchemist.foodbook_presentation.WITHDRAW')->execute(['foodbook_id' => $fb->id], $this->kontext);
    expect($wd->success)->toBeTrue();
    $get2 = $this->registry->get('foodalchemist.foodbook_presentation.GET')->execute(['foodbook_id' => $fb->id], $this->kontext);
    expect($get2->data['enabled'])->toBeFalse();
    $this->get('/p/foodbook/' . $pub->data['token'])->assertNotFound();
});

it('PUBLISH ohne expires_at → VALIDATION_ERROR (Pflicht-Datum)', function () {
    $fb = ($this->baue)($this->rootTeam);
    $res = $this->registry->get('foodalchemist.foodbook_presentation.PUBLISH')->execute(['foodbook_id' => $fb->id], $this->kontext);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('VALIDATION_ERROR');
});

it('PUBLISH auf fremd-Team-Foodbook → NOT_FOUND', function () {
    $fremd = ($this->baue)($this->childB);
    // Kontext = childA (Geschwister sehen childB nicht).
    $kontextA = new ToolContext($this->makeUser($this->childA), $this->childA);
    $res = $this->registry->get('foodalchemist.foodbook_presentation.PUBLISH')->execute([
        'foodbook_id' => $fremd->id, 'expires_at' => now()->addDays(30)->toDateString(),
    ], $kontextA);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND');
});

it('presentation_designs: custom_css + tokens + layout Round-Trip (MCP kann CSS/Blöcke setzen)', function () {
    $post = $this->registry->get('foodalchemist.presentation_designs.POST')->execute([
        'name' => 'Broich',
        'base_slug' => 'editorial',
        'tokens_json' => ['palette' => ['accent' => '#b02530'], 'nav' => 'sidebar', 'lightbox' => true],
        'layout_json' => [
            ['block_type' => 'cover', 'style' => ['show_cover_image' => true]],
            ['block_type' => 'chapter_loop', 'style' => ['show_dish_photos' => true, 'show_chapter_image' => false]],
        ],
        'custom_css' => '.pt-line-price { font-weight: 800; }',
    ], $this->kontext);
    expect($post->success)->toBeTrue();

    $get = $this->registry->get('foodalchemist.presentation_designs.GET')->execute(['id' => $post->data['id']], $this->kontext);
    expect($get->data['tokens_json']['palette']['accent'])->toBe('#b02530');
    expect($get->data['tokens_json']['nav'])->toBe('sidebar');
    expect(collect($get->data['layout_json'])->firstWhere('block_type', 'chapter_loop')['style']['show_dish_photos'])->toBeTrue();
    expect($get->data['custom_css'])->toContain('font-weight: 800');
});

it('presentation_designs CRUD Round-Trip; fremd-Team-PUT → NOT_FOUND', function () {
    $post = $this->registry->get('foodalchemist.presentation_designs.POST')->execute([
        'name' => 'MCP-Design', 'base_slug' => 'kiosk',
    ], $this->kontext);
    expect($post->success)->toBeTrue();
    $id = $post->data['id'];

    $get = $this->registry->get('foodalchemist.presentation_designs.GET')->execute(['id' => $id], $this->kontext);
    expect($get->data['name'])->toBe('MCP-Design')->and($get->data['base_slug'])->toBe('kiosk');

    $search = $this->registry->get('foodalchemist.presentation_designs.SEARCH')->execute(['q' => 'MCP'], $this->kontext);
    expect(collect($search->data['designs'])->pluck('id'))->toContain($id);

    $put = $this->registry->get('foodalchemist.presentation_designs.PUT')->execute(['id' => $id, 'name' => 'MCP-Design 2'], $this->kontext);
    expect($put->success)->toBeTrue();

    // fremd-Team-Design (childB) via childA-Kontext → NOT_FOUND.
    $fremdDesign = app(\Platform\FoodAlchemist\Services\PresentationDesignService::class)->create($this->childB, ['name' => 'Fremd', 'base_slug' => 'menu']);
    $kontextA = new ToolContext($this->makeUser($this->childA), $this->childA);
    $putFremd = $this->registry->get('foodalchemist.presentation_designs.PUT')->execute(['id' => $fremdDesign->id, 'name' => 'Klau'], $kontextA);
    expect($putFremd->success)->toBeFalse()->and($putFremd->errorCode)->toBe('NOT_FOUND');

    $del = $this->registry->get('foodalchemist.presentation_designs.DELETE')->execute(['id' => $id], $this->kontext);
    expect($del->success)->toBeTrue();
});
