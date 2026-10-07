<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\BearbeitungssperreService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 65 · Bearbeitungssperre: Editor öffnet zum Lesen, „Bearbeiten" sperrt für andere, Speichern/Abbrechen gibt frei,
 * der Server weist Schreibaktionen ohne eigene Sperre ab.
 */
beforeEach(function () {
    config(['foodalchemist.bearbeitungssperre' => true]);
    $this->seedTeamHierarchy();
    $this->gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'brisket', 'name' => 'Brisket', 'status' => 'approved', 'is_sales_recipe' => true,
    ]);
    $this->anna = $this->makeUser($this->rootTeam, 'Anna');
    $this->ben = $this->makeUser($this->rootTeam, 'Ben');
    $this->svc = app(BearbeitungssperreService::class);
});

it('Dienst: eine Person sperrt, die zweite wird mit Inhaber abgewiesen, Freigabe macht frei', function () {
    expect($this->svc->sperren('recipe', $this->gericht->id, $this->anna->id, 'Anna')['ok'])->toBeTrue();
    $b = $this->svc->sperren('recipe', $this->gericht->id, $this->ben->id, 'Ben');
    expect($b['ok'])->toBeFalse()->and($b['inhaber']['name'])->toBe('Anna');

    $this->svc->freigeben('recipe', $this->gericht->id, $this->anna->id);
    expect($this->svc->sperren('recipe', $this->gericht->id, $this->ben->id, 'Ben')['ok'])->toBeTrue();
});

it('Dienst: abgelaufene Sperre (15 Min ohne Aktivität) ist frei', function () {
    $this->svc->sperren('recipe', $this->gericht->id, $this->anna->id, 'Anna');
    DB::table('foodalchemist_bearbeitungssperren')->update(['laeuft_ab' => now()->subMinute()]);

    expect($this->svc->haelt('recipe', $this->gericht->id, $this->anna->id))->toBeFalse()
        ->and($this->svc->sperren('recipe', $this->gericht->id, $this->ben->id, 'Ben')['ok'])->toBeTrue();
});

it('Gericht-Editor: ohne „Bearbeiten" Lesemodus und Speichern wird serverseitig abgewiesen', function () {
    $this->actingAs($this->anna);
    $c = Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id)
        ->assertSeeHtml('data-bearbeiten-starten')
        ->assertSeeHtml('data-fa-lesemodus="1"')
        ->assertDontSeeHtml('data-vk-speichern');

    $c->set('form.name', 'Geändert')->call('speichern');
    expect($this->gericht->refresh()->name)->toBe('Brisket');
});

it('Gericht-Editor: „Bearbeiten" schaltet frei, Speichern schreibt, Abbrechen gibt frei', function () {
    $this->actingAs($this->anna);
    $c = Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id)
        ->call('bearbeitenStarten')
        ->assertSeeHtml('data-vk-speichern')
        ->assertSeeHtml('data-fa-lesemodus="0"');
    expect($this->svc->haelt('recipe', $this->gericht->id, $this->anna->id))->toBeTrue();

    $c->set('form.name', 'Brisket neu')->call('speichern');
    expect($this->gericht->refresh()->name)->toBe('Brisket neu');

    $c->call('bearbeitenAbbrechen');
    expect($this->svc->haelt('recipe', $this->gericht->id, $this->anna->id))->toBeFalse();
});

it('Gericht-Editor: zweite Person sieht „wird bearbeitet" und kann nicht schreiben', function () {
    $this->svc->sperren('recipe', $this->gericht->id, $this->anna->id, 'Anna');
    $this->actingAs($this->ben);

    $c = Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id)
        ->assertSeeHtml('data-bearbeiten-fremd')->assertSee('Wird von Anna bearbeitet')
        ->call('bearbeitenStarten');
    expect($this->svc->haelt('recipe', $this->gericht->id, $this->ben->id))->toBeFalse();

    $c->set('form.name', 'Von Ben')->call('speichern');
    expect($this->gericht->refresh()->name)->toBe('Brisket');
});

it('Gericht-Editor: Schließen gibt die eigene Sperre frei', function () {
    $this->actingAs($this->anna);
    Livewire::test(VkModal::class)->call('oeffnen', $this->gericht->id)
        ->call('bearbeitenStarten')
        ->dispatch('modal.closed', name: 'vk-modal');
    expect($this->svc->haelt('recipe', $this->gericht->id, $this->anna->id))->toBeFalse();
});
