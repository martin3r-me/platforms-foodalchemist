<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\PraesentationsDesigns;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\OfferCompositionService;
use Platform\FoodAlchemist\Services\PresentationDesignService;
use Platform\FoodAlchemist\Services\PresentationFontService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Präsentation Stufe 1 (2026-11-12): Gericht-Fotos im Angebot, Schriftwahl (Überschrift + Fließtext),
 * eigene Team-Schriften (@font-face) und freie Präsentationsbilder je Angebot (image-Block quelle=angebot_N).
 */
beforeEach(function () {
    Storage::fake('public');
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->pres = app(PresentationService::class);
    $this->comp = app(OfferCompositionService::class);
    $this->angebot = app(AngebotService::class)->create($this->rootTeam, ['name' => 'Stufe 1', 'personen' => 50]);
});

function stufe1Design(\Platform\Core\Models\Team $team, array $layout, array $tokens = []): string
{
    $d = app(PresentationDesignService::class)->create($team, [
        'name' => 'Test', 'base_slug' => 'editorial', 'layout_json' => $layout,
        'tokens_json' => $tokens + ['typography' => ['heading' => 'display-serif', 'body' => 'sans', 'scale' => 1.0]],
    ]);

    return 'design:' . $d->id;
}

it('Gericht-Fotos: Angebots-Items tragen das Foto, das Design entscheidet über die Anzeige', function () {
    $gericht = $this->makeRecipe($this->rootTeam, 'Ochsenbacke', ['is_sales_recipe' => true, 'sales_wording_standard' => 'Ochsenbacke']);
    app(\Platform\FoodAlchemist\Services\RecipeService::class)->storeDishImage($this->rootTeam, $gericht->id, UploadedFile::fake()->image('o.jpg', 300, 200));
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Menü', 'kind' => 'concept', 'status' => 'active']);
    $this->makeConceptSlot($c, ['position' => 0, 'type' => 'gericht', 'sales_recipe_id' => $gericht->id]);
    $kap = $this->comp->addKapitel($this->rootTeam, $this->angebot->id, ['title' => 'Menü']);
    $this->comp->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $c->id]);

    $snap = $this->pres->buildSnapshot($this->rootTeam, $this->angebot->fresh(), 'angebot', ['design' => 'editorial']);
    $item = $snap['content']['sections'][0]['blocks'][0]['items'][0];
    expect($item['image'])->not->toBeNull()->and($item)->not->toHaveKey('recipe_id');

    $design = stufe1Design($this->rootTeam, [['block_type' => 'cover', 'style' => []], ['block_type' => 'chapter_loop', 'style' => ['show_dish_photos' => true]]]);
    $res = $this->pres->publish($this->rootTeam, 'angebot', $this->angebot->id, ['design' => $design, 'expires_at' => now()->addDays(3)->toDateString()]);
    $this->get('/p/angebot/' . $res['token'])->assertOk()->assertSeeHtml('pt-item-foto');
});

it('Schriftwahl: Überschrift und Fließtext getrennt, Google-Fonts und eigene Schriften in der Oberfläche', function () {
    app(PresentationFontService::class)->upload($this->rootTeam, UploadedFile::fake()->create('brand.woff2', 20, 'font/woff2'), 'Green Tasty Sans', 700);

    Livewire::test(PraesentationsDesigns::class)
        ->assertSeeHtml('data-fa-schrift="heading"')->assertSeeHtml('data-fa-schrift="body"')
        ->assertSee('Cormorant Garamond')
        ->assertSeeHtml('value="team:Green Tasty Sans"')
        ->assertSee('Eigene Schriften');
});

it('Eigene Schrift: Upload validiert, Snapshot friert sie ein, Seite schreibt @font-face', function () {
    $svc = app(PresentationFontService::class);
    expect(fn () => $svc->upload($this->rootTeam, UploadedFile::fake()->create('x.exe', 5), 'Böse'))->toThrow(RuntimeException::class);
    expect(PresentationFontService::familie('Brand"; } body{x:1'))->toBe('Brand bodyx1');   // Anführungszeichen, Klammern, Doppelpunkt fliegen raus

    $svc->upload($this->rootTeam, UploadedFile::fake()->create('brand.woff2', 20, 'font/woff2'), 'Green Tasty Sans', 400);
    $svc->upload($this->rootTeam, UploadedFile::fake()->create('brand-bold.woff2', 20, 'font/woff2'), 'Green Tasty Sans', 700);
    expect($svc->list($this->rootTeam))->toHaveCount(2);

    $design = stufe1Design($this->rootTeam, [['block_type' => 'cover', 'style' => []]], ['typography' => ['heading' => 'team:Green Tasty Sans', 'body' => 'Manrope', 'scale' => 1.0]]);
    $res = $this->pres->publish($this->rootTeam, 'angebot', $this->angebot->id, ['design' => $design, 'expires_at' => now()->addDays(3)->toDateString()]);
    $this->get('/p/angebot/' . $res['token'])->assertOk()
        ->assertSeeHtml('@font-face { font-family: "Green Tasty Sans"')
        ->assertSeeHtml('font-weight: 700')
        ->assertSeeHtml('--pt-heading-font: "Green Tasty Sans", Georgia')
        ->assertSeeHtml('family=Manrope');
});

it('Präsentationsbild je Angebot: image-Block mit quelle=angebot_1 zeigt das Angebotsbild, ohne Bild bleibt er leer', function () {
    $design = stufe1Design($this->rootTeam, [['block_type' => 'cover', 'style' => []], ['block_type' => 'image', 'style' => ['quelle' => 'angebot_1']]]);
    $res = $this->pres->publish($this->rootTeam, 'angebot', $this->angebot->id, ['design' => $design, 'expires_at' => now()->addDays(3)->toDateString()]);
    $this->get('/p/angebot/' . $res['token'])->assertOk()->assertDontSeeHtml('<figure class="pt-image-band');

    app(AngebotService::class)->storePraesentationsBild($this->rootTeam, $this->angebot->id, 1, UploadedFile::fake()->image('b1.jpg', 600, 300));
    $res = $this->pres->publish($this->rootTeam, 'angebot', $this->angebot->id, ['design' => $design, 'expires_at' => now()->addDays(3)->toDateString()]);
    $this->get('/p/angebot/' . $res['token'])->assertOk()->assertSeeHtml('<figure class="pt-image-band');

    app(AngebotService::class)->clearPraesentationsBild($this->rootTeam, $this->angebot->id, 1);
    expect($this->angebot->fresh()->presentation_images_json)->toBeNull();
});
