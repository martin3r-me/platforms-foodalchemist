<?php

use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 43 — Share-Card fürs Link-Vorschaubild (Open Graph): 1200×630-PNG aus dem Snapshot.
 * Ohne hochgeladenes Cover rendert die Karte auf markenfarbenem Grund (valides PNG mit Titel).
 * Bewusst OHNE actingAs — echter Gast-Zugriff.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(PresentationService::class);

    $this->publishFb = function (array $over = []) {
        $fb = $this->makeFoodbook($this->rootTeam, 'Foodbook 2027', ['personen' => 10, 'jahr' => 2027]);
        $kap = $this->makeChapter($fb, ['title' => 'INTERN', 'consumer_title' => 'Vorspeisen', 'position' => 1]);
        $suppe = $this->makeRecipe($this->rootTeam, 'Kürbissuppe', ['is_sales_recipe' => true, 'sales_net' => 6.5]);
        $this->makeFoodbookBlock($kap, ['type' => 'recipe_ref', 'sales_recipe_id' => $suppe->id, 'position' => 1]);
        $res = $this->svc->publish($this->rootTeam, 'foodbook', $fb->id, array_merge([
            'expires_at' => now()->addDays(30)->toDateString(),
        ], $over));

        return [$fb, $res];
    };
});

it('liefert ein valides 1200×630-PNG als Share-Card', function () {
    if (! function_exists('imagettftext')) {
        $this->markTestSkipped('GD/FreeType nicht verfügbar.');
    }
    [, $res] = ($this->publishFb)();

    $resp = $this->get('/p/foodbook/' . $res['token'] . '/share-card.png')->assertOk();
    expect($resp->headers->get('Content-Type'))->toContain('image/png');

    $png = $resp->getContent();
    $info = getimagesizefromstring($png);
    expect($info)->not->toBeFalse()
        ->and($info[0])->toBe(1200)
        ->and($info[1])->toBe(630)
        ->and($info['mime'])->toBe('image/png');
});

it('auch per Slug erreichbar; nach Zurückziehen 404', function () {
    if (! function_exists('imagettftext')) {
        $this->markTestSkipped('GD/FreeType nicht verfügbar.');
    }
    [$fb, $res] = ($this->publishFb)(['slug' => 'broich-2027']);

    $this->get('/p/foodbook/broich-2027/share-card.png')->assertOk();

    $this->svc->withdraw($this->rootTeam, 'foodbook', $fb->id);
    $this->get('/p/foodbook/' . $res['token'] . '/share-card.png')->assertNotFound();
});

it('die Seite verweist mit og:image auf die Share-Card (+ Maße)', function () {
    [, $res] = ($this->publishFb)();
    $html = $this->get('/p/foodbook/' . $res['token'])->assertOk()->getContent();

    expect($html)
        ->toContain('/share-card.png')
        ->toContain('property="og:image"')
        ->toContain('property="og:image:width" content="1200"')
        ->toContain('property="og:image:height" content="630"');
});
