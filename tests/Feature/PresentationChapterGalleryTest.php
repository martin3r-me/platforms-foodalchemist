<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 43 — Kapitel-Galerie (Mehrfach-Bild am Foodbook-Kapitel, Vorrang vor Concept)
 * + Rondell-Band-Variante.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Bild'));
    $this->pres = app(PresentationService::class);
    $this->fbSvc = app(FoodbookService::class);
    Storage::fake('public');

    $this->baue = function ($team) {
        $concept = $this->makeConcept($team, 'Menü', ['kind' => 'concept', 'consumer_name' => 'Genuss']);
        $concept->update(['image_path' => 'foodalchemist/concept/titel.jpg']);
        $dish = $this->makeRecipe($team, 'Lachs', ['is_sales_recipe' => true, 'sales_net' => 12.0]);
        $this->makeConceptSlot($concept, ['sales_recipe_id' => $dish->id, 'wording' => 'Lachs', 'position' => 1]);
        $fb = $this->makeFoodbook($team, 'Katalog', ['personen' => 6]);
        $kap = $this->makeChapter($fb, ['title' => 'Vorspeisen', 'consumer_title' => 'Vorspeisen', 'position' => 1]);
        $this->makeFoodbookBlock($kap, ['type' => 'concept_ref', 'concept_id' => $concept->id, 'position' => 1]);

        return [$fb, $kap];
    };
});

it('FoodbookService::addKapitelGalleryImage/removeKapitelGalleryImage pflegt die Kapitel-Galerie', function () {
    [$fb, $kap] = ($this->baue)($this->rootTeam);

    $a = $this->fbSvc->addKapitelGalleryImage($this->rootTeam, $kap->id, UploadedFile::fake()->image('k1.jpg', 400, 300));
    $this->fbSvc->addKapitelGalleryImage($this->rootTeam, $kap->id, UploadedFile::fake()->image('k2.jpg', 400, 300));
    expect($kap->images()->count())->toBe(2);

    $this->fbSvc->removeKapitelGalleryImage($this->rootTeam, $a->id);
    expect($kap->images()->count())->toBe(1);
});

it('Kapitel-Galerie hat Vorrang vor den Concept-Bildern im Band', function () {
    [$fb, $kap] = ($this->baue)($this->rootTeam);
    $kap->images()->create(['team_id' => $this->rootTeam->id, 'path' => 'foodalchemist/chapter/eigen1.jpg', 'sort_order' => 1]);
    $kap->images()->create(['team_id' => $this->rootTeam->id, 'path' => 'foodalchemist/chapter/eigen2.jpg', 'sort_order' => 2]);

    $snap = $this->pres->buildSnapshot($this->rootTeam, $fb->refresh(), 'foodbook', ['expires_at' => now()->addDays(30)->toDateString()]);
    $imgs = collect($snap['content']['sections'][0]['images'])->pluck('path');

    expect($imgs)->toContain('foodalchemist/chapter/eigen1.jpg')->toContain('foodalchemist/chapter/eigen2.jpg');
    expect($imgs)->not->toContain('foodalchemist/concept/titel.jpg'); // Concept-Fallback greift NICHT
});

it('ohne Kapitel-Bilder greift der Concept-Fallback', function () {
    [$fb, $kap] = ($this->baue)($this->rootTeam);
    $snap = $this->pres->buildSnapshot($this->rootTeam, $fb->refresh(), 'foodbook', ['expires_at' => now()->addDays(30)->toDateString()]);
    expect(collect($snap['content']['sections'][0]['images'])->pluck('path'))->toContain('foodalchemist/concept/titel.jpg');
});

it('Rondell-Band-Variante rendert das Karussell-Markup', function () {
    [$fb, $kap] = ($this->baue)($this->rootTeam);
    $kap->images()->create(['team_id' => $this->rootTeam->id, 'path' => 'foodalchemist/chapter/a.jpg', 'sort_order' => 1]);
    $kap->images()->create(['team_id' => $this->rootTeam->id, 'path' => 'foodalchemist/chapter/b.jpg', 'sort_order' => 2]);

    $res = $this->pres->publish($this->rootTeam, 'foodbook', $fb->id, [
        'expires_at' => now()->addDays(30)->toDateString(),
        'tokens' => ['band_style' => 'rondell'],
    ]);

    $this->get('/p/foodbook/' . $res['token'])
        ->assertOk()
        ->assertSee('data-pt-rondell', false)
        ->assertSee('pt-rondell-track', false);
});
