<?php

use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Verkauf\DetailPanel as VkDetailPanel;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Livewire\Recipes\DetailPanel as RezeptDetailPanel;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\FassungService;
use Platform\FoodAlchemist\Support\FassungMenue;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 §13 B6 (Lücke aus #353): Gerichte lassen sich veröffentlichen. Der Menüpunkt „Veröffentlichen …“ steht im Gericht-Modal (VK) und im
 * Gericht-Detailpanel mit DERSELBEN Bedingung wie bei Basisrezepten, gehalten an einer Stelle (Support\FassungMenue).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();
    $this->kurator = $this->makeUser($this->master, 'Kurator', 'owner');
    $this->broichChef = $this->makeUser($this->broich, 'Broich Chef', 'owner');

    $this->ei = FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'ei', 'name' => 'Ei']);
    $this->jus = $this->makeRecipe($this->master, 'Kalbsjus');
    $this->makeIngredient($this->jus, 'Ei', $this->ei, '100');
    $this->gericht = $this->makeRecipe($this->master, '[HG] Senfrostbraten | Anrichten', ['is_sales_recipe' => true]);
    FoodAlchemistRecipeIngredient::create([
        'team_id' => $this->master->id, 'recipe_id' => $this->gericht->id, 'referenced_recipe_id' => $this->jus->id, 'raw_text' => 'Kalbsjus',
        'quantity' => '100', 'unit_vocab_id' => $this->unitG($this->master)->id, 'position' => 1,
    ]);
    $this->broichGericht = $this->makeRecipe($this->broich, '[HG] Broich-Gericht | Anrichten', ['is_sales_recipe' => true]);
});

it('Gericht-Modal (VK): „Veröffentlichen …“ erscheint für den Master bei seinem Gericht, für einen Mandanten nicht', function () {
    $this->actingAs($this->kurator);
    $master = Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id)->html();
    expect($master)->toContain('data-fassung-menue')->toContain('Veröffentlichen …')->toContain('fassung-modal.oeffnen');

    $this->actingAs($this->broichChef);
    $mandant = Livewire::test(VkModal::class)->call('oeffnen', $this->broichGericht->id)->html();
    expect($mandant)->not->toContain('data-fassung-menue')->not->toContain('Veröffentlichen …');
});

it('Gericht-Detailpanel (Verkauf): „Veröffentlichen …“ erscheint für den Master bei seinem Gericht, für einen Mandanten nicht', function () {
    $this->actingAs($this->kurator);
    Livewire::test(VkDetailPanel::class, ['recipeId' => $this->gericht->id])->assertSeeHtml('data-fassung-menue')->assertSee('Veröffentlichen …');

    $this->actingAs($this->broichChef);
    Livewire::test(VkDetailPanel::class, ['recipeId' => $this->broichGericht->id])->assertDontSeeHtml('data-fassung-menue')->assertDontSee('Veröffentlichen …');
});

it('Basisrezept-Detailpanel nutzt dieselbe Bedingung (Master ja, Mandant nein)', function () {
    $this->actingAs($this->kurator);
    Livewire::test(RezeptDetailPanel::class, ['recipeId' => $this->jus->id])->assertSeeHtml('data-fassung-menue');

    $broichJus = $this->makeRecipe($this->broich, 'Broich Jus');
    $this->actingAs($this->broichChef);
    Livewire::test(RezeptDetailPanel::class, ['recipeId' => $broichJus->id])->assertDontSeeHtml('data-fassung-menue');
});

it('Die Bedingung steht an einer Stelle: nur Master-Team, nur eigene Arbeitszeile, nie eine Fassung', function () {
    $mandantTeam = $this->broich;
    expect(FassungMenue::sichtbar($this->master, $this->gericht))->toBeTrue()->and(FassungMenue::sichtbar($this->master, $this->gericht->id))->toBeTrue()
        ->and(FassungMenue::sichtbar($mandantTeam, $this->gericht))->toBeFalse()               // Mandant sieht den Punkt nie
        ->and(FassungMenue::sichtbar($this->master, $this->broichGericht))->toBeFalse()        // Master, aber fremdes Rezept
        ->and(FassungMenue::sichtbar(null, $this->gericht))->toBeFalse()->and(FassungMenue::sichtbar($this->master, null))->toBeFalse()
        ->and(FassungMenue::sichtbar($this->master, 99999))->toBeFalse();

    $r = app(FassungService::class)->veroeffentlichen($this->master, $this->jus->id, 'Erste Fassung', $this->kurator);
    $fassung = FoodAlchemistRecipe::mitFassungen()->findOrFail($r['fassung_id']);
    expect(FassungMenue::sichtbar($this->master, $fassung))->toBeFalse()                       // eine Fassung wird nie veröffentlicht
        ->and(FassungMenue::sichtbar($this->master, $fassung->id))->toBeFalse();
});

it('Das Veröffentlichen eines Gerichts nimmt seine Komponenten mit (Fassung je Rezept im Baum)', function () {
    $r = app(FassungService::class)->veroeffentlichen($this->master, $this->gericht->id, 'Gericht samt Jus', $this->kurator);

    $fassungen = FoodAlchemistRecipe::mitFassungen()->where('ist_fassung', true)->whereIn('fassung_von_id', [$this->gericht->id, $this->jus->id])->get();
    expect($r['neu'])->toHaveCount(2)->and($fassungen)->toHaveCount(2)
        ->and($fassungen->pluck('fassung_von_id')->sort()->values()->all())->toBe(collect([$this->gericht->id, $this->jus->id])->sort()->values()->all());
});
