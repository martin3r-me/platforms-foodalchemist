<?php

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamSetting;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\SalesRecipeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 50 · A1 — der Team-Default für die Aufschlagsklasse muss die Anlage überleben.
 *
 * `SalesRecipeService::createLeer`/`createFromBasis` übergaben `markup_class_id` an
 * `RecipeService::create()`, dessen `create([...])`-Array den Key nicht kannte — der Default
 * fiel still auf den Boden. Etappe-0-Messung 2026-09-05: **926 von 950 Gerichten ohne
 * Aufschlagsklasse**, und ohne sie greift die Kaskade in `wirtschaftlichkeitsGlied` nicht.
 *
 * Dieselbe Fehlerklasse hatte das Modul schon zweimal (Stufe-3-Planerfelder, #509 Create-
 * Parität). Die Tests riegeln sie für dieses Feld ab — inklusive der Tenancy-Grenze: eine
 * Aufschlagsklasse ist eine REFERENZ und wird team-scoped autorisiert, nicht roh übernommen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->mkKlasse = fn (Team $team, string $code) => FoodAlchemistMarkupClass::create([
        'team_id' => $team->id, 'code' => $code, 'label' => "Klasse {$code}",
        'raw_markup_pct' => 300, 'is_inactive' => false,
    ]);

    $this->klasse = ($this->mkKlasse)($this->rootTeam, 'STD');
});

it('A1: createLeer setzt den Team-Default statt ihn zu verwerfen', function () {
    FoodAlchemistTeamSetting::updateOrCreate(
        ['team_id' => $this->rootTeam->id],
        ['default_markup_class_id' => $this->klasse->id]
    );

    $gericht = app(SalesRecipeService::class)->createLeer($this->rootTeam, '[HG] Testgericht');

    expect($gericht->vk_klasse?->id)->toBe($this->klasse->id);   // Spec 85 D-1: an der Standard-Darreichung
});

it('A1: ohne Angabe bleibt die Klasse leer — kein geratener Default', function () {
    $r = app(RecipeService::class)->create($this->rootTeam, ['name' => '[HG] Ohne Klasse', 'is_sales_recipe' => true]);

    expect($r->vk_klasse)->toBeNull();
});

// Spec 85 D-1 PR-C: `RecipeService::create()` nimmt keine Aufschlagsklasse mehr an (die Rezept-Spalte ist weg). Die Klasse
// setzt die Darreichung: Vorbelegung → Spec85AufschlagsklasseDarreichungTest, Tenancy über updateVk → ReferenzAutorisierungTest.
