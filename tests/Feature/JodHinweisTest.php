<?php

use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\EtikettService;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tools\GpsGetTool;
use Platform\FoodAlchemist\Tools\RecipesGetTool;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Jod (15.10.2026, Teil b): freiwillige Angabe am Rezept mitaggregiert (ALL-MAXIMAL), getrennt ausgewiesen, NIE in Pflicht-
 * Kennzeichnung, Zusatzstoff-Nummer oder Legende. Hinweis „mit Jodsalz hergestellt“ auf Speisekarte/Etikett nur bei
 * Mandanten-Schalter (org-vererbt, Standard aus).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->settings = app(TeamSettingsService::class);
    $lieferant = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Lieferant']);
    $this->gp = $this->makeGp($this->rootTeam, 'Speisesalz');
    $this->la = function (array $deklaration) use ($lieferant) {
        $item = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lieferant->id, 'designation' => 'LA '.uniqid()]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, 'gp_id' => $this->gp->id]);
        FoodAlchemistItemDeclaration::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, ...$deklaration]);
    };
    // Gericht mit Jod und einem Pflicht-Zusatzstoff, direkt gesetzt (Kennzeichnung liest die aggregierten Spalten)
    $this->gericht = $this->makeRecipe($this->rootTeam, 'Brezel', ['is_sales_recipe' => true, 'sales_net' => 4.0]);
    $this->gericht->forceFill(['additive_iodized' => 3, 'additive_with_dye' => 3, 'allergens_confidence' => 'high'])->save();
});

it('GP und Rezept: Jod wird ALL-MAXIMAL mitaggregiert, aber nicht in den Pflicht-Stoffen', function () {
    ($this->la)(['iodized' => 1, 'with_dye' => 1]);
    ($this->la)(['iodized' => 3]);
    $agg = app(GpAggregateService::class);

    expect($agg->freiwillig($this->gp))->toBe(['iodized' => 3])
        ->and($agg->zusatzstoffe($this->gp))->not->toHaveKey('iodized')
        ->and($agg->zusatzstoffeUndFreiwillig($this->gp)['iodized'])->toBe(3);

    $r = $this->makeRecipe($this->rootTeam, 'Brotteig');
    $this->makeIngredient($r, 'Salz', $this->gp);
    app(RecipeRecomputeService::class)->recomputePipeline($r->id);
    expect((int) $r->fresh()->additive_iodized)->toBe(3)->and((int) $r->fresh()->additive_with_dye)->toBe(1);
});

it('Schalter: Standard aus, Oberteam schaltet, Unter-Team erbt, eigener Wert gewinnt', function () {
    expect($this->settings->jodHinweisAktiv($this->rootTeam))->toBeFalse()->and($this->settings->jodHinweisAktiv($this->childA))->toBeFalse();

    $this->settings->update($this->rootTeam, ['iodized_hint_active' => true]);
    expect($this->settings->jodHinweisAktiv($this->childA))->toBeTrue();

    $this->settings->update($this->childB, ['iodized_hint_active' => false]);
    expect($this->settings->jodHinweisAktiv($this->childB))->toBeFalse()->and($this->settings->jodHinweisAktiv($this->childA))->toBeTrue();
});

it('Speisekarte: Hinweis nur mit Schalter, nie als Nummer oder in der Legende', function () {
    $karten = app(SpeisekarteService::class);
    $karte = $karten->create($this->rootTeam, ['name' => 'Karte']);
    $rubrik = $karten->addRubrik($this->rootTeam, $karte->id, ['title' => 'Backwaren']);
    $karten->addPosition($this->rootTeam, $rubrik->id, ['type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id]);

    $aus = $karten->dokumentDaten($this->rootTeam, $karte->refresh());
    $this->settings->update($this->rootTeam, ['iodized_hint_active' => true]);
    $an = $karten->dokumentDaten($this->rootTeam, $karte->refresh());

    $posAus = $aus['rubriken'][0]['positionen'][0];
    $posAn = $an['rubriken'][0]['positionen'][0];
    expect($posAus['hinweise'])->toBe([])
        ->and($posAn['hinweise'])->toBe(['mit Jodsalz hergestellt'])
        ->and($posAn['codes'])->toBe($posAus['codes'])                                    // Codes unverändert: nur Farbstoff (1)
        ->and(collect($an['legende']['zusatzstoffe'])->pluck('label')->all())->toBe(['mit Farbstoff']);
});

it('Etikett: Hinweis nur mit Schalter, Zusatzstoffe ohne Jod', function () {
    $svc = app(EtikettService::class);
    $aus = $svc->daten($this->rootTeam, 'recipe', $this->gericht->id);
    $this->settings->update($this->rootTeam, ['iodized_hint_active' => true]);
    $an = $svc->daten($this->rootTeam, 'recipe', $this->gericht->id);

    expect($aus['hinweise'])->toBe([])->and($an['hinweise'])->toBe(['mit Jodsalz hergestellt'])
        ->and(collect($an['zusatzstoffe'])->pluck('label')->all())->toBe(['mit Farbstoff']);
});

it('MCP: freiwillig getrennt von den Pflicht-Stoffen (GP und Rezept)', function () {
    ($this->la)(['iodized' => 3]);
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $ctx = new ToolContext($user, $this->rootTeam);

    $gp = app(GpsGetTool::class)->execute(['id' => $this->gp->id], $ctx);
    $basis = $this->makeRecipe($this->rootTeam, 'Salzlake');
    $basis->forceFill(['additive_iodized' => 1])->save();
    $rezept = app(RecipesGetTool::class)->execute(['id' => $basis->id], $ctx);

    expect($gp->data['freiwillig'])->toBe(['jodiert' => 'ja'])
        ->and($rezept->data['freiwillig'] ?? null)->toBe(['jodiert' => 'nein']);
});

it('Einstellungen → Etiketten: Schalter schreibt die Team-Zeile und zeigt den Zustand', function () {
    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire\Livewire::test(\Platform\FoodAlchemist\Livewire\Settings\Etiketten::class)
        ->assertSeeHtml('data-jod-schalter')->assertSet('jodHinweis', false)
        ->call('jodHinweisUmschalten')->assertSet('jodHinweis', true);

    expect($this->settings->jodHinweisAktiv($this->rootTeam))->toBeTrue()->and($this->settings->jodHinweisAktiv($this->childA))->toBeTrue();
});
