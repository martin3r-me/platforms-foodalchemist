<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 43 — Eigener Link-Name (Slug) für die öffentliche Präsentation: optional, normalisiert,
 * je Ausgabeform eindeutig; der Zufalls-Token bleibt als Fallback. Additiv — Bestandslinks
 * (nur Token) müssen weiter funktionieren.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(PresentationService::class);

    $this->baue = function ($team, string $name = 'Sommerkatalog') {
        $fb = $this->makeFoodbook($team, $name, ['personen' => 10, 'jahr' => 2027]);
        $kap = $this->makeChapter($fb, ['title' => 'INTERN', 'consumer_title' => 'Vorspeisen', 'position' => 1]);
        $suppe = $this->makeRecipe($team, 'Kürbissuppe', ['is_sales_recipe' => true, 'sales_net' => 6.5]);
        $this->makeFoodbookBlock($kap, ['type' => 'recipe_ref', 'sales_recipe_id' => $suppe->id, 'position' => 1]);

        return $fb;
    };

    $this->publish = fn ($fb, array $over = []) => $this->svc->publish($this->rootTeam, 'foodbook', $fb->id, array_merge([
        'expires_at' => now()->addDays(30)->toDateString(),
    ], $over));
});

it('Slug wird normalisiert, gespeichert und löst den Public-Link auf', function () {
    $fb = ($this->baue)($this->rootTeam);
    $res = ($this->publish)($fb, ['slug' => 'Broich Empfang 2027']);

    expect($res['slug'])->toBe('broich-empfang-2027')
        ->and($res['url'])->toContain('/p/foodbook/broich-empfang-2027');
    expect($fb->refresh()->presentation_slug)->toBe('broich-empfang-2027');

    // Slug-Route auflösbar …
    $this->get('/p/foodbook/broich-empfang-2027')->assertOk()->assertSee('Vorspeisen');
    // … UND der Token bleibt als Fallback gültig (additiv).
    $this->get('/p/foodbook/' . $fb->presentation_token)->assertOk()->assertSee('Vorspeisen');
});

it('leerer Slug setzt zurück auf Token-only', function () {
    $fb = ($this->baue)($this->rootTeam);
    ($this->publish)($fb, ['slug' => 'mein-name']);
    expect($fb->refresh()->presentation_slug)->toBe('mein-name');

    ($this->publish)($fb, ['slug' => '']);
    expect($fb->refresh()->presentation_slug)->toBeNull();
    $this->get('/p/foodbook/mein-name')->assertNotFound();
    $this->get('/p/foodbook/' . $fb->presentation_token)->assertOk();
});

it('Slug-Kollision (fremder Slug/Token derselben Ausgabeform) wird abgewiesen', function () {
    $a = ($this->baue)($this->rootTeam, 'Katalog A');
    ($this->publish)($a, ['slug' => 'gala-2027']);

    $b = ($this->baue)($this->rootTeam, 'Katalog B');
    expect(fn () => ($this->publish)($b, ['slug' => 'gala-2027']))
        ->toThrow(\RuntimeException::class);
    // B darf durch die Kollision NICHT still den fremden Slug bekommen haben.
    expect($b->refresh()->presentation_slug)->toBeNull();
});

it('MCP: PUBLISH nimmt slug entgegen, GET liefert slug + Slug-URL', function () {
    $fb = ($this->baue)($this->rootTeam);
    $registry = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);

    $pub = $registry->get('foodalchemist.foodbook_presentation.PUBLISH')->execute([
        'foodbook_id' => $fb->id,
        'expires_at' => now()->addDays(30)->toDateString(),
        'slug' => 'Chefs Corner · Empfang',
    ], $ctx);
    expect($pub->success)->toBeTrue('pub: ' . ($pub->error ?? ''))
        ->and($pub->data['slug'])->toBe('chefs-corner-empfang');

    $get = $registry->get('foodalchemist.foodbook_presentation.GET')->execute(['foodbook_id' => $fb->id], $ctx);
    expect($get->success)->toBeTrue()
        ->and($get->data['slug'])->toBe('chefs-corner-empfang')
        ->and($get->data['url'])->toContain('/p/foodbook/chefs-corner-empfang');
});
