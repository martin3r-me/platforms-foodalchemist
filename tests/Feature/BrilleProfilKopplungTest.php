<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamBetrieb;
use Platform\FoodAlchemist\Services\ActiveOutletContext;
use Platform\FoodAlchemist\Services\OutletSettingsService;
use Platform\FoodAlchemist\Services\StandortService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Kopplung Team-Brille ↔ Kalkulationsprofil (Dominique/Kurator 19.10.): im Hauptteam stellt die Brille „Unter-Team X“ das
 * Profil auf den X zugeordneten Betrieb (Preise wie X sie sieht); „eigen“/„alle“ → Team-Standard; danach frei wählbar bis zum
 * nächsten Brillenwechsel. Unter-Team unverändert fest. Druckwege rechnen wie die Seite: dokument-gebunden ?? aktives Profil.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->standorte = app(StandortService::class);
    $this->ctx = app(ActiveOutletContext::class);
    $this->nord = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Kantine Nord']);
    $this->sued = FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Kantine Süd']);
    FoodAlchemistTeamBetrieb::create(['team_id' => $this->childA->id, 'outlet_id' => $this->nord->id]);   // A → Nord, B ohne Zuordnung
});

it('Brille „Unter-Team A“ setzt das Profil auf den Betrieb von A; „eigen“ und „alle“ zurück auf den Team-Standard', function () {
    $this->standorte->setzeBrille($this->rootTeam, 'team', $this->childA->id);
    expect($this->ctx->current($this->rootTeam)?->id)->toBe($this->nord->id);

    $this->standorte->setzeBrille($this->rootTeam, 'alle');
    expect($this->ctx->current($this->rootTeam))->toBeNull();

    $this->standorte->setzeBrille($this->rootTeam, 'team', $this->childA->id);
    $this->standorte->setzeBrille($this->rootTeam, 'eigen');
    expect($this->ctx->current($this->rootTeam))->toBeNull();
});

it('Unter-Team ohne Zuordnung → Team-Standard (auch wenn vorher ein Profil gewählt war)', function () {
    $this->ctx->set($this->rootTeam, $this->sued->id);

    $this->standorte->setzeBrille($this->rootTeam, 'team', $this->childB->id);

    expect($this->ctx->current($this->rootTeam))->toBeNull();
});

it('manuelles Profil bleibt bis zum nächsten echten Brillenwechsel — dieselbe Brille erneut wählen ändert nichts', function () {
    $this->standorte->setzeBrille($this->rootTeam, 'team', $this->childA->id);
    $this->ctx->set($this->rootTeam, $this->sued->id);                          // manuell übersteuert

    $this->standorte->setzeBrille($this->rootTeam, 'team', $this->childA->id);  // gleiche Brille
    expect($this->ctx->current($this->rootTeam)?->id)->toBe($this->sued->id);

    $this->standorte->setzeBrille($this->rootTeam, 'alle');                     // echter Wechsel
    expect($this->ctx->current($this->rootTeam))->toBeNull();
});

it('im Unter-Team bleibt das Profil fest auf dem zugeordneten Betrieb', function () {
    expect($this->ctx->current($this->childA)?->id)->toBe($this->nord->id)
        ->and($this->ctx->set($this->childA, null)?->id)->toBe($this->nord->id)
        ->and($this->standorte->setzeBrille($this->childA, 'eigen'))->toBe(['modus' => 'eigen', 'team_id' => null])
        ->and($this->ctx->current($this->childA)?->id)->toBe($this->nord->id);
});

it('MCP standorte.SET_BRILLE koppelt genauso', function () {
    $res = app(ToolRegistry::class)->get('foodalchemist.standorte.SET_BRILLE')
        ->execute(['modus' => 'team', 'team_id' => $this->childA->id], new ToolContext($this->user, $this->rootTeam));

    expect($res->success)->toBeTrue()
        ->and($this->ctx->current($this->rootTeam, (int) $this->user->id)?->id)->toBe($this->nord->id);
});

it('Profil-Leiste sagt, dass das Profil der Brille folgt — nach manuellem Umstellen nicht mehr', function () {
    $this->standorte->setzeBrille($this->rootTeam, 'team', $this->childA->id);
    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\ActiveOutletBar::class)
        ->assertSeeHtml('data-betrieb-folgt-brille')->assertSee('Kind A');

    $this->ctx->set($this->rootTeam, $this->sued->id);
    \Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\ActiveOutletBar::class)->assertDontSeeHtml('data-betrieb-folgt-brille');
});

it('Druck: die Speisekarte rechnet gegen das aktive Profil wie die Seite; ein an der Karte gebundener Betrieb gewinnt', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['target_food_cost_pct' => 25]);
    app(OutletSettingsService::class)->update($this->rootTeam, $this->nord, ['target_food_cost_pct' => 20]);   // Basissatz 5,0
    $gericht = $this->makeRecipe($this->rootTeam, 'HG Zander', ['is_sales_recipe' => true]);
    $sf = FoodAlchemistServierform::create(['team_id' => $this->rootTeam->id, 'code' => 'teller', 'label' => 'Teller']);
    FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->rootTeam->id, 'recipe_id' => $gericht->id, 'serving_form_id' => $sf->id,
        'is_standard' => true, 'ek_portion' => 10, 'sales_net' => 99.0,   // Team-Basis 99 (wie AusgabeDriftTest), Betrieb Nord = 10 × 5,0
    ]);
    $reg = app(ToolRegistry::class);
    $k = new ToolContext($this->user, $this->rootTeam);
    $karteId = $reg->get('foodalchemist.speisekarten.POST')->execute(['name' => 'Abendkarte'], $k)->data['speisekarte']['id'];
    $rubrikId = $reg->get('foodalchemist.speisekarte_rubrik.POST')->execute(['speisekarte_id' => $karteId, 'title' => 'Fisch', 'art' => 'speisen'], $k)->data['rubrik']['id'];
    $reg->get('foodalchemist.speisekarte_positionen.POST')->execute(['rubrik_id' => $rubrikId, 'type' => 'gericht_ref', 'sales_recipe_id' => $gericht->id], $k);
    $vk = fn () => (float) $this->get(route('foodalchemist.speisekarte.dokument', $karteId))->assertOk()->viewData('rubriken')[0]['positionen'][0]['vk_netto'];

    $ohne = $vk();
    $this->ctx->set($this->rootTeam, $this->nord->id);
    $mit = $vk();
    expect($ohne)->toBe(99.0)->and($mit)->not->toBe($ohne);

    // Gebundener Betrieb an der Karte gewinnt gegen das aktive Profil
    \Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte::whereKey($karteId)->update(['outlet_id' => $this->nord->id]);
    $this->ctx->set($this->rootTeam, null);
    expect($vk())->toBe($mit);
});
