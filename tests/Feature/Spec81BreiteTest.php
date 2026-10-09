<?php

use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Conformance\GpConformanceAdapter;
use Platform\FoodAlchemist\Services\Conformance\RecipeConformanceAdapter;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;
use Platform\FoodAlchemist\Services\Regeln\RegelService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Spec 81 Paket 7 — Verkaufsgerichte, Kostformen, GP §8/§12, Schritt-Mengen: neu = aus, eingeschaltet = Code-Befund. */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->an = function (string ...$schluessel): void {
        foreach ($schluessel as $k) {
            app(RegelService::class)->setzeAktiv(FoodAlchemistRule::where('schluessel', $k)->firstOrFail()->id, true);
        }
    };
    $this->befunde = fn (int $id) => app(RecipeConformanceAdapter::class)->deterministischeBefunde($this->rootTeam, $id);
});

it('neue Regeln starten aus, umgezogene VK-Prüfungen sind aktiv', function () {
    expect(RegelBuch::falls('ernaehrung.vegan'))->toBeNull()
        ->and(RegelBuch::falls('gp.8.12.kartoffel'))->toBeNull()
        ->and(RegelBuch::falls('vk.1.2.marker'))->not->toBeNull()
        ->and(RegelBuch::falls('vk.1.2.grammatur'))->not->toBeNull();
});

it('Kostform: vegan ausgelobtes Rezept mit Honig und Vollmilch, Kokosmilch bleibt erlaubt', function () {
    ($this->an)('ernaehrung.vegan');
    $r = $this->makeRecipe($this->rootTeam, 'Dressing: Senf-Honig', ['status' => 'draft']);
    $r->update(['spec_is_vegan' => true]);
    foreach (['Honig', 'Vollmilch 3,5 %', 'Kokosmilch', 'Reis'] as $i => $t) {
        FoodAlchemistRecipeIngredient::create(['team_id' => $r->team_id, 'recipe_id' => $r->id, 'raw_text' => $t, 'quantity' => '10',
            'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => $i + 1]);
    }

    $kostform = collect(($this->befunde)($r->id))->where('rule_id', FoodAlchemistRule::where('schluessel', 'ernaehrung.vegan')->value('id'));

    expect($kostform->pluck('feld')->all())->toBe(['zutat:Honig', 'zutat:Vollmilch 3,5 %'])
        ->and($kostform->first()['quelle'])->toBe('code')
        ->and($kostform->first()['schweregrad'])->toBe('hart');
});

it('Verkaufsgericht: Kürzel, Bausteine, Diät-Tag, Füllwörter und Mengen im Schritt', function () {
    ($this->an)('vk.1.1.hg', 'vk.1.1.bausteine', 'vk.1.2.diaet', 'vk.1.2.fuellwoerter', 'vk.3.8.schritt_mengen');
    $vk = $this->makeRecipe($this->rootTeam, '[APE] Falafel mit Hummus (vegan)', ['status' => 'draft', 'is_sales_recipe' => true]);
    FoodAlchemistRecipeStep::create(['team_id' => $vk->team_id, 'recipe_id' => $vk->id, 'position' => 1, 'text' => 'Mit 10 g Salz mischen und 10 Min. bei 180 °C backen.', 'ebene' => FoodAlchemistRecipeStep::EBENE_PRODUKTION]);

    $par = collect(($this->befunde)($vk->id))->groupBy('paragraph')->map->count();

    expect($par['§1.1'])->toBe(2)              // [APE] nicht im Vokabular + 1 Baustein statt 3–5
        ->and($par['§1.2'])->toBe(2)           // (vegan) + „mit"
        ->and($par['§3.8'])->toBe(1);          // „10 g Salz" — Zeit und Temperatur bleiben erlaubt
});

it('GP §8: Pflichtangabe meldet nur unter der Bedingung, beim Anlegen als Hinweis', function () {
    ($this->an)('gp.8.12.kartoffel', 'gp.8.11.mehl');
    $n = app(GpNamingService::class);

    expect($n->validateGpName('Kartoffel: frisch, ganz', ['hauptzutat' => 'Kartoffel', 'condition' => 'frisch'])['warnings'])
        ->toContain('§8.12: Kochtyp fehlt (mehlig, vorwiegend festkochend, festkochend) (§8.12).')
        ->and($n->validateGpName('Kartoffel Linda: frisch, vorwiegend festkochend', ['hauptzutat' => 'Kartoffel Linda', 'condition' => 'frisch'])['warnings'])
        ->not->toContain('§8.12: Kochtyp fehlt (mehlig, vorwiegend festkochend, festkochend) (§8.12).')
        ->and(implode(' ', $n->validateGpName('Süßkartoffel: frisch, ganz', ['hauptzutat' => 'Süßkartoffel', 'condition' => 'frisch'])['warnings']))
        ->not->toContain('§8.12');

    $gp = $this->makeGp($this->rootTeam, 'Weizenmehl: trocken');
    expect(collect(app(GpConformanceAdapter::class)->deterministischeBefunde($this->rootTeam, $gp->id))->pluck('paragraph')->all())->toContain('§8.11');
});

it('Datenqualität liest Grammatur und Marker aus den Regeln', function () {
    $regel = FoodAlchemistRule::where('schluessel', 'vk.1.2.marker')->firstOrFail();
    app(RegelService::class)->setzeAktiv($regel->id, false);

    expect(RegelBuch::falls('vk.1.2.marker'))->toBeNull();   // aus = Datenqualität prüft Marker nicht mehr (Einstellung wirkt ohne Deploy)
});
