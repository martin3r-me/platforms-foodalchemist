<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Aufschlagsklassen;
use Platform\FoodAlchemist\Models\FoodAlchemistDishClass;
use Platform\FoodAlchemist\Models\FoodAlchemistDishMainGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistMarkupClass;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\DarreichungResolver;
use Platform\FoodAlchemist\Services\DarreichungService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 D-1 PR-B: Die Aufschlagsklasse eines Gerichts ist die Klasse seiner Standard-Darreichung.
 * Beim Anlegen einer Darreichung wird sie vorbelegt: Speisen-Klasse → Hauptgruppe → Team-Standard.
 * Die Rezeptspalte `markup_class_id` ist nur noch markierter Rückfall bis PR-C.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $klasse = fn (string $code) => FoodAlchemistMarkupClass::create([
        'team_id' => $this->rootTeam->id, 'code' => $code, 'label' => $code, 'class_factor_pct' => 100,
        'raw_markup_pct' => 300, 'vat_rate' => 19, 'formula_type' => 'aufschlag',
    ]);
    $this->akKlasse = $klasse('KLS');
    $this->akHg = $klasse('HGR');
    $this->akTeam = $klasse('TEAM');
    $this->hg = FoodAlchemistDishMainGroup::create(['team_id' => $this->rootTeam->id, 'code' => 'TEL', 'label' => 'Teller', 'default_markup_class_id' => $this->akHg->id]);
    $this->speisenKlasse = FoodAlchemistDishClass::create([
        'team_id' => $this->rootTeam->id, 'dish_main_group_id' => $this->hg->id, 'code' => 'TEL-OMN', 'label' => 'Teller omnivor',
        'diet_form' => 'omnivor', 'default_markup_class_id' => $this->akKlasse->id,
    ]);
    app(TeamSettingsService::class)->update($this->rootTeam, ['default_markup_class_id' => $this->akTeam->id]);
    $this->sf = FoodAlchemistServierform::firstOrCreate(['code' => 'unbestimmt', 'team_id' => $this->rootTeam->id], ['label' => 'Unbestimmt']);
    $this->gericht = fn (array $attr = []) => $this->makeRecipe($this->rootTeam, 'HG: Klasse ' . uniqid(), ['is_sales_recipe' => true, ...$attr]);
});

it('Vorbelegung beim Anlegen: Speisen-Klasse vor Hauptgruppe vor Team-Standard', function () {
    $svc = app(DarreichungService::class);

    $mitKlasse = ($this->gericht)(['dish_class_id' => $this->speisenKlasse->id, 'dish_main_group_id' => $this->hg->id]);
    expect($svc->ensureStandard($this->rootTeam, $mitKlasse->id)->markup_class_id)->toBe($this->akKlasse->id);

    $nurHg = ($this->gericht)(['dish_main_group_id' => $this->hg->id]);
    expect($svc->ensureStandard($this->rootTeam, $nurHg->id)->markup_class_id)->toBe($this->akHg->id);

    $nackt = ($this->gericht)();
    expect($svc->ensureStandard($this->rootTeam, $nackt->id)->markup_class_id)->toBe($this->akTeam->id);
});

it('klasseFuer / vk_klasse: die Klasse der Standard-Darreichung gilt, die Rezeptspalte nur als Rückfall', function () {
    $gericht = ($this->gericht)(['markup_class_id' => $this->akTeam->id]);
    $resolver = app(DarreichungResolver::class);
    expect($resolver->klasseFuer($gericht->fresh())?->id)->toBe($this->akTeam->id);   // Rückfall: noch keine Darreichung

    FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $gericht->id, 'serving_form_id' => $this->sf->id,
        'is_standard' => true, 'markup_class_id' => $this->akHg->id,
    ]);
    expect($resolver->klasseFuer($gericht->fresh())?->id)->toBe($this->akHg->id)
        ->and($gericht->fresh()->vk_klasse?->code)->toBe('HGR');
});

it('Aufschlagsklassen: Löschschutz und Zähler zählen Gerichte über ihre Darreichungen', function () {
    $gericht = ($this->gericht)();
    foreach ([true, false] as $std) {   // zwei Formen desselben Gerichts → ein Gericht
        FoodAlchemistRecipeDarreichung::create([
            'team_id' => $this->rootTeam->id, 'recipe_id' => $gericht->id,
            'serving_form_id' => $std ? $this->sf->id : FoodAlchemistServierform::firstOrCreate(['code' => 'platte', 'team_id' => $this->rootTeam->id], ['label' => 'Platte'])->id,
            'is_standard' => $std, 'markup_class_id' => $this->akHg->id,
        ]);
    }

    Livewire::test(Aufschlagsklassen::class)
        ->assertViewHas('zaehler', fn ($z) => (int) ($z[$this->akHg->id] ?? 0) === 1)
        ->call('delete', $this->akHg->id)
        ->assertSet('fehler', fn ($f) => is_string($f) && str_contains($f, 'Wird von 1 Gericht'));

    expect(FoodAlchemistMarkupClass::find($this->akHg->id))->not->toBeNull();
});
