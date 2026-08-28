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
        ->assertSee('<img class="pt-section-img"', false);
});

it('Kapitel-Bild überschreibt das Concept-Titelbild', function () {
    $team = $this->rootTeam;
    $fb = ($this->baue)($team, 'foodalchemist/concept/genuss.jpg', 'foodalchemist/chapter/eigen.jpg');
    $this->pres->publish($team, 'foodbook', $fb->id, ['expires_at' => now()->addDays(30)->toDateString()]);

    $snap = $fb->refresh()->presentation_snapshot_json;
    expect($snap['content']['sections'][0]['image']['path'])->toBe('foodalchemist/chapter/eigen.jpg');
});

it('ohne Bilder bleibt das Kapitel-Band bildlos (kein Fehler)', function () {
    $team = $this->rootTeam;
    $fb = ($this->baue)($team);
    $res = $this->pres->publish($team, 'foodbook', $fb->id, ['expires_at' => now()->addDays(30)->toDateString()]);

    $snap = $fb->refresh()->presentation_snapshot_json;
    expect($snap['content']['sections'][0]['image'])->toBeNull();
    $this->get('/p/foodbook/' . $res['token'])->assertOk()->assertDontSee('<img class="pt-section-img"', false);
});
