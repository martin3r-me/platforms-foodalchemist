<?php

use Illuminate\Support\Carbon;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanLinie;
use Platform\FoodAlchemist\Services\PresentationDesignService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 43 — Builder-Extras: Speiseplan-Liste-Variante + freier Bild-Block + Cover-Fit.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->pres = app(PresentationService::class);
    $this->designs = app(PresentationDesignService::class);

    $this->bauePlan = function ($team) {
        $mo = Carbon::parse('2027-06-07')->startOfWeek(Carbon::MONDAY);
        $plan = FoodAlchemistSpeiseplan::create(['team_id' => $team->id, 'name' => 'Wochenplan', 'status' => 'draft', 'start_date' => $mo->format('Y-m-d')]);
        $line = FoodAlchemistSpeiseplanLinie::create(['team_id' => $team->id, 'menu_plan_id' => $plan->id, 'name' => 'Menü 1', 'sort_order' => 1]);
        $dish = $this->makeRecipe($team, 'HG Gulasch', ['is_sales_recipe' => true, 'sales_net' => 4.5]);
        FoodAlchemistSpeiseplanEintrag::create([
            'team_id' => $team->id, 'menu_plan_id' => $plan->id, 'line_id' => $line->id,
            'week' => 1, 'weekday' => 1, 'meal' => 'mittag', 'entry_date' => $mo->format('Y-m-d'),
            'sales_recipe_id' => $dish->id, 'position' => 1,
        ]);

        return $plan;
    };
});

it('Speiseplan-Ausgabe „liste" rendert Tag-Sektionen statt Wochenraster', function () {
    $plan = ($this->bauePlan)($this->rootTeam);

    $grid = $this->pres->buildSnapshot($this->rootTeam, $plan, 'speiseplan', ['design' => 'kiosk']);
    expect(collect($grid['resolved_design']['layout'])->pluck('block_type'))->toContain('grid')->not->toContain('chapter_loop');

    $liste = $this->pres->buildSnapshot($this->rootTeam, $plan, 'speiseplan', ['design' => 'kiosk', 'tokens' => ['speiseplan_layout' => 'liste']]);
    expect(collect($liste['resolved_design']['layout'])->pluck('block_type'))->toContain('chapter_loop')->not->toContain('grid');
    expect($liste['content']['sections'])->not->toBeEmpty();
    expect($liste['content']['sections'][0]['blocks'])->not->toBeEmpty();
});

it('freier Bild-Block: Identifier in der Layout-Definition wird zur Render-Zeit signiert', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Katalog', ['personen' => 4]);
    $design = $this->designs->create($this->rootTeam, [
        'name' => 'Mit Bild',
        'layout_json' => [
            ['block_type' => 'cover', 'style' => []],
            ['block_type' => 'image', 'style' => ['path' => 'foodalchemist/presentation_design/frei.jpg']],
        ],
    ]);

    $snap = $this->pres->buildSnapshot($this->rootTeam, $fb, 'foodbook', ['design' => 'design:' . $design->id]);
    $snap = $this->pres->hydrateImages($snap);

    $imgBlock = collect($snap['resolved_design']['layout'])->firstWhere('block_type', 'image');
    expect($imgBlock)->not->toBeNull();
    expect($imgBlock['style']['url'] ?? null)->toContain('foodalchemist/presentation_design/frei.jpg');
});

it('storeBlockImage legt ein team-scoped Design-Bild ab (Identifier zurück)', function () {
    Illuminate\Support\Facades\Storage::fake('public');
    $media = $this->designs->storeBlockImage($this->rootTeam, Illuminate\Http\UploadedFile::fake()->image('frei.jpg', 400, 300));
    expect($media['path'])->not->toBeNull();
});
