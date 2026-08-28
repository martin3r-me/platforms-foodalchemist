<?php

use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 43 (Bild-Epic) — Kapitel-Band-Bild in der Präsentation: Concept-Titelbild als
 * Grundlage, Kapitel-Bild überschreibt. Snapshot friert Identifier, Render signiert frisch.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->pres = app(PresentationService::class);
    $this->actingAs($this->makeUser($this->rootTeam, 'Bild'));

    $this->baue = function ($team, $conceptImagePath = null, $chapterImagePath = null) {
        $concept = $this->makeConcept($team, 'Menü A', ['kind' => 'concept', 'consumer_name' => 'Genuss']);
        if ($conceptImagePath !== null) {
            $concept->update(['image_path' => $conceptImagePath]);
        }
        $dish = $this->makeRecipe($team, 'Lachs', ['is_sales_recipe' => true, 'sales_net' => 12.0]);
        $this->makeConceptSlot($concept, ['sales_recipe_id' => $dish->id, 'wording' => 'Lachs', 'position' => 1]);

        $fb = $this->makeFoodbook($team, 'Bild-Katalog', ['personen' => 6]);
        $kap = $this->makeChapter($fb, ['title' => 'Vorspeisen', 'consumer_title' => 'Vorspeisen', 'position' => 1]);
        if ($chapterImagePath !== null) {
            $kap->update(['image_path' => $chapterImagePath]);
        }
        $this->makeFoodbookBlock($kap, ['type' => 'concept_ref', 'concept_id' => $concept->id, 'position' => 1]);

        return $fb;
    };
});

it('Concept-Titelbild wird zum Kapitel-Band-Bild (Grundlage) + im Public-Render signiert', function () {
    $team = $this->rootTeam;
    $fb = ($this->baue)($team, 'foodalchemist/concept/genuss.jpg');
    $res = $this->pres->publish($team, 'foodbook', $fb->id, ['expires_at' => now()->addDays(30)->toDateString()]);

    $snap = $fb->refresh()->presentation_snapshot_json;
    expect($snap['content']['sections'][0]['image']['path'])->toBe('foodalchemist/concept/genuss.jpg');

    $this->get('/p/foodbook/' . $res['token'])
        ->assertOk()
        ->assertSee('foodalchemist/concept/genuss.jpg', false)   // frisch signierte <img>-URL
        ->assertSee('class="pt-section-img', false);
});

it('Kapitel-Bild überschreibt das Concept-Titelbild', function () {
    $team = $this->rootTeam;
    $fb = ($this->baue)($team, 'foodalchemist/concept/genuss.jpg', 'foodalchemist/chapter/eigen.jpg');
    $this->pres->publish($team, 'foodbook', $fb->id, ['expires_at' => now()->addDays(30)->toDateString()]);

    $snap = $fb->refresh()->presentation_snapshot_json;
    expect($snap['content']['sections'][0]['image']['path'])->toBe('foodalchemist/chapter/eigen.jpg');
});

it('Concept-Galerie füllt die Bild-Liste (Titel + Galeriebild) als 2er-Band', function () {
    $team = $this->rootTeam;
    $concept = $this->makeConcept($team, 'Menü G', ['kind' => 'concept', 'consumer_name' => 'Genuss']);
    $concept->update(['image_path' => 'foodalchemist/concept/titel.jpg']);
    $concept->images()->create(['team_id' => $team->id, 'path' => 'foodalchemist/concept/gallery/extra.jpg', 'sort_order' => 1]);
    $dish = $this->makeRecipe($team, 'Lachs', ['is_sales_recipe' => true, 'sales_net' => 12.0]);
    $this->makeConceptSlot($concept, ['sales_recipe_id' => $dish->id, 'wording' => 'Lachs', 'position' => 1]);
    $fb = $this->makeFoodbook($team, 'Galerie-Katalog', ['personen' => 6]);
    $kap = $this->makeChapter($fb, ['title' => 'Vorspeisen', 'consumer_title' => 'Vorspeisen', 'position' => 1]);
    $this->makeFoodbookBlock($kap, ['type' => 'concept_ref', 'concept_id' => $concept->id, 'position' => 1]);

    $res = $this->pres->publish($team, 'foodbook', $fb->id, ['expires_at' => now()->addDays(30)->toDateString()]);

    $imgs = $fb->refresh()->presentation_snapshot_json['content']['sections'][0]['images'];
    expect($imgs)->toHaveCount(2);
    expect($imgs[0]['path'])->toBe('foodalchemist/concept/titel.jpg');
    expect($imgs[1]['path'])->toBe('foodalchemist/concept/gallery/extra.jpg');

    $this->get('/p/foodbook/' . $res['token'])
        ->assertOk()
        ->assertSee('pt-section-gallery', false)
        ->assertSee('foodalchemist/concept/gallery/extra.jpg', false);
});

it('ohne Bilder bleibt das Kapitel-Band bildlos (kein Fehler)', function () {
    $team = $this->rootTeam;
    $fb = ($this->baue)($team);
    $res = $this->pres->publish($team, 'foodbook', $fb->id, ['expires_at' => now()->addDays(30)->toDateString()]);

    $snap = $fb->refresh()->presentation_snapshot_json;
    expect($snap['content']['sections'][0]['image'])->toBeNull();
    $this->get('/p/foodbook/' . $res['token'])->assertOk()->assertDontSee('class="pt-section-img', false);
});
