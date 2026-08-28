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

it('Gericht-Foto: Identifier im Snapshot, gerendert nur mit Design-Toggle', function () {
    $team = $this->rootTeam;
    $concept = $this->makeConcept($team, 'Menü', ['kind' => 'concept', 'consumer_name' => 'Genuss']);
    $dish = $this->makeRecipe($team, 'Lachs', ['is_sales_recipe' => true, 'sales_net' => 12.0]);
    $dish->update(['image_path' => 'foodalchemist/recipe/lachs.jpg']);
    $this->makeConceptSlot($concept, ['sales_recipe_id' => $dish->id, 'wording' => 'Lachs', 'position' => 1]);
    $fb = $this->makeFoodbook($team, 'Katalog', ['personen' => 6]);
    $kap = $this->makeChapter($fb, ['title' => 'Vorspeisen', 'consumer_title' => 'Vorspeisen', 'position' => 1]);
    $this->makeFoodbookBlock($kap, ['type' => 'concept_ref', 'concept_id' => $concept->id, 'position' => 1]);

    // Identifier ist IMMER im Snapshot (Anzeige entscheidet das Design).
    $snap = $this->pres->buildSnapshot($team, $fb, 'foodbook', ['expires_at' => now()->addDays(30)->toDateString()]);
    $itemImg = collect($snap['content']['sections'][0]['blocks'])->flatMap(fn ($b) => $b['items'] ?? [])->pluck('image.path')->filter();
    expect($itemImg)->toContain('foodalchemist/recipe/lachs.jpg');

    // Nach Hydrieren trägt das Item eine frische Foto-URL (Identifier → URL).
    $hyd = $this->pres->hydrateImages($snap);
    $urls = collect($hyd['content']['sections'][0]['blocks'])->flatMap(fn ($b) => $b['items'] ?? [])->pluck('image.url')->filter();
    expect($urls)->not->toBeEmpty();

    // Ohne Toggle: kein Foto-<img> im HTML (die CSS-Regel .pt-item-foto steht immer im <style>,
    // deshalb auf das MARKUP prüfen, nicht auf den bloßen Klassennamen).
    $ohne = $this->pres->publish($team, 'foodbook', $fb->id, ['expires_at' => now()->addDays(30)->toDateString()]);
    $this->get('/p/foodbook/' . $ohne['token'])->assertOk()->assertDontSee('<img class="pt-item-foto', false);

    // Mit Design-Toggle show_dish_photos: Foto sichtbar.
    $design = app(\Platform\FoodAlchemist\Services\PresentationDesignService::class)->create($team, [
        'name' => 'Mit Fotos',
        'layout_json' => [
            ['block_type' => 'cover', 'style' => []],
            ['block_type' => 'chapter_loop', 'style' => ['show_dish_photos' => true]],
        ],
    ]);
    // Design wird aufgelöst (chapter_loop mit show_dish_photos)?
    $snapMit = $this->pres->buildSnapshot($team, $fb->refresh(), 'foodbook', ['design' => 'design:' . $design->id, 'expires_at' => now()->addDays(30)->toDateString()]);
    $cl = collect($snapMit['resolved_design']['layout'])->firstWhere('block_type', 'chapter_loop');
    expect($cl['style']['show_dish_photos'] ?? false)->toBeTrue();

    $mit = $this->pres->publish($team, 'foodbook', $fb->id, ['design' => 'design:' . $design->id, 'expires_at' => now()->addDays(30)->toDateString()]);
    $stored = $fb->refresh()->presentation_snapshot_json;
    expect(collect($stored['resolved_design']['layout'])->pluck('block_type'))->not->toContain('price_summary'); // mein 2-Block-Design, nicht editorial
    $storedCl = collect($stored['resolved_design']['layout'])->firstWhere('block_type', 'chapter_loop');
    expect($storedCl['style']['show_dish_photos'] ?? false)->toBeTrue();

    // Mit Toggle: Foto-<img> IST im HTML.
    $this->get('/p/foodbook/' . $mit['token'])->assertOk()->assertSee('<img class="pt-item-foto', false);
});

it('Konzept-interne Header (AUMSE/STARTER) rendern fett als pt-subheader', function () {
    $team = $this->rootTeam;
    $concept = $this->makeConcept($team, 'Menü', ['kind' => 'concept', 'consumer_name' => 'Chefs Corner']);
    $this->makeConceptSlot($concept, ['type' => 'header', 'title' => 'AUMSE – Avantgarde Bites', 'position' => 1, 'wording' => '']);
    $dish = $this->makeRecipe($team, 'Curry-Hummus', ['is_sales_recipe' => true, 'sales_net' => 5.5]);
    $this->makeConceptSlot($concept, ['sales_recipe_id' => $dish->id, 'wording' => 'Curry-Hummus', 'position' => 2]);

    $fb = $this->makeFoodbook($team, 'Katalog', ['personen' => 6]);
    $kap = $this->makeChapter($fb, ['title' => 'Vorspeisen', 'consumer_title' => 'Vorspeisen', 'position' => 1]);
    $this->makeFoodbookBlock($kap, ['type' => 'concept_ref', 'concept_id' => $concept->id, 'position' => 1]);

    $res = $this->pres->publish($team, 'foodbook', $fb->id, ['expires_at' => now()->addDays(30)->toDateString()]);
    $this->get('/p/foodbook/' . $res['token'])
        ->assertOk()
        ->assertSee('<h4 class="pt-subheader', false)
        ->assertSee('AUMSE – Avantgarde Bites', false);
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
