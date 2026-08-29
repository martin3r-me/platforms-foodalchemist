<?php

use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 43 — Mobil-Ausbau der öffentlichen Präsentation (rein ADDITIV): PWA-Web-App-Manifest,
 * Open-Graph/Twitter-Link-Vorschau, iOS-Safe-Area-Viewport. Darf die bestehende Desktop-Ausgabe
 * nicht verändern → letzter Test prüft, dass das Kern-Rendering unangetastet bleibt.
 * Bewusst OHNE actingAs — echter Gast-Zugriff.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PresentationService::class);

    $fb = $this->makeFoodbook($this->rootTeam, 'Sommerkatalog', [
        'personen' => 10, 'jahr' => 2027, 'description' => 'Feine Auswahl', 'brand_color' => '#123456',
    ]);
    $kap = $this->makeChapter($fb, ['title' => 'INTERN Vorspeisen', 'consumer_title' => 'Vorspeisen', 'position' => 1]);
    $suppe = $this->makeRecipe($this->rootTeam, 'Kürbissuppe', ['is_sales_recipe' => true, 'sales_net' => 6.5]);
    $this->makeFoodbookBlock($kap, ['type' => 'recipe_ref', 'sales_recipe_id' => $suppe->id, 'position' => 1]);

    $res = $this->svc->publish($this->rootTeam, 'foodbook', $fb->id, ['expires_at' => now()->addDays(30)->toDateString()]);
    $this->token = $res['token'];
});

it('die öffentliche Seite trägt PWA- + Open-Graph-Meta (mobil/Teilen)', function () {
    $html = $this->get('/p/foodbook/' . $this->token)->assertOk()->getContent();

    // PWA / Homescreen
    expect($html)
        ->toContain('name="theme-color"')
        ->toContain('name="apple-mobile-web-app-capable"')
        ->toContain('rel="manifest"')
        ->toContain('viewport-fit=cover');

    // Link-Vorschau beim Teilen
    expect($html)
        ->toContain('property="og:type"')
        ->toContain('property="og:title"')
        ->toContain('Sommerkatalog')
        ->toContain('name="twitter:card"');
});

it('das Web-App-Manifest ist gültig und aus demselben Snapshot aufgebaut', function () {
    $res = $this->get('/p/foodbook/' . $this->token . '/app.webmanifest')->assertOk();

    expect($res->headers->get('Content-Type'))->toContain('application/manifest+json');

    $m = $res->json();
    expect($m['name'])->toBe('Sommerkatalog')
        ->and($m['display'])->toBe('standalone')
        ->and($m['start_url'])->toContain('/p/foodbook/' . $this->token)
        ->and($m['scope'])->toContain('/p/foodbook/' . $this->token)
        ->and($m['theme_color'])->not->toBeEmpty()
        ->and($m['icons'][0]['type'])->toBe('image/svg+xml')
        ->and($m['icons'][0]['src'])->toStartWith('data:image/svg+xml;base64,');
});

it('Manifest folgt der 404-Matrix (unbekannter/zurückgezogener Token)', function () {
    $this->get('/p/foodbook/deadbeefdeadbeef/app.webmanifest')->assertNotFound();
});

it('die Desktop-Ausgabe bleibt unangetastet (Kern-Markup + Inhalt unverändert)', function () {
    $html = $this->get('/p/foodbook/' . $this->token)->assertOk()
        ->assertSee('Sommerkatalog')
        ->assertSee('Vorspeisen')
        ->assertSee('Kürbissuppe')
        ->assertDontSee('INTERN Vorspeisen')
        ->getContent();

    // Editorial-Chassis + Design-Token-Pipeline stehen weiterhin (kein Layout-Bruch).
    expect($html)
        ->toContain('class="pt-hero')
        ->toContain('--pt-primary:')
        ->toContain('fonts.googleapis.com');
});
