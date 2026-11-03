<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\ConcepterAggregateService;
use Platform\FoodAlchemist\Services\GpAggregateService;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Support\AlkoholAngabe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\GpsGetTool;
use Platform\FoodAlchemist\Tools\GpsPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 94c · Alkohol (Kita): fester Wert am GP (`gps.alkohol` ja / nein / NULL + Herkunft, alle Warengruppen; Kurator 03.11.2026) mit Vorrang
 * vor den Artikeln (is_alcohol = 1 ja, 0 = keine Angabe) → Rezept (spec_contains_alcohol, Warn-Regel: nein nur, wenn alle Zutaten nein sind)
 * → Codes und Legende („Alk.“). Vorbelegung der Getränke (WG 15) über foodalchemist:alkohol-vorbelegen, Probelauf als Standard.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    $this->lieferant = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Getränke Hof']);
    $this->artikel = function ($gp, ?bool $alkohol, string $nr) {
        $item = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->lieferant->id,
            'designation' => 'Artikel ' . $nr, 'article_number' => $nr, 'qty' => 1, 'unit_code' => 'l', 'is_alcohol' => $alkohol]);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $item->id, 'gp_id' => $gp->id]);
    };
    $this->gp = function (string $name, string $wg, ?string $pflicht = null, ?string $sub = null) {
        return tap($this->makeGp($this->rootTeam, $name))->update(['commodity_group_code' => $wg, 'pflichtangabe' => $pflicht, 'sub_category' => $sub])->fresh();
    };
});

it('GP: fester Wert vor den Artikeln; Artikel nur ja aus is_alcohol = 1, is_alcohol = 0 heißt keine Angabe', function () {
    $agg = app(GpAggregateService::class);
    $wein = $this->makeGp($this->rootTeam, 'Weißwein');
    ($this->artikel)($wein, false, 'W0');
    ($this->artikel)($wein, true, 'W1');
    $saft = $this->makeGp($this->rootTeam, 'Apfelsaft');
    ($this->artikel)($saft, false, 'S1');
    $mirin = $this->makeGp($this->rootTeam, 'Mirin');
    ($this->artikel)($mirin, false, 'M1');
    $mirin->update(['alkohol' => true, 'alkohol_source' => 'manuell']);
    $cola = $this->makeGp($this->rootTeam, 'Coca-Cola');
    ($this->artikel)($cola, true, 'C1');                                  // falscher Artikelwert: der GP-Wert gewinnt
    $cola->update(['alkohol' => false, 'alkohol_source' => 'abgeleitet']);

    expect($agg->enthaeltAlkohol($wein))->toBeTrue()
        ->and($agg->enthaeltAlkohol($saft))->toBeNull()
        ->and($agg->enthaeltAlkohol($mirin->fresh()))->toBeTrue()
        ->and($agg->enthaeltAlkohol($cola->fresh()))->toBeFalse()
        ->and($agg->enthaeltAlkohol($this->makeGp($this->rootTeam, 'Ohne Artikel')))->toBeNull();
});

it('Rezept: über GP und Sub-Rezept hoch; nein nur bei echtem nein aller Zutaten, unbekannt nie alkoholfrei; Code „Alk.“ mit Legende', function () {
    $wein = $this->makeGp($this->rootTeam, 'Weißwein');
    ($this->artikel)($wein, true, 'W1');
    $saft = tap($this->makeGp($this->rootTeam, 'Apfelsaft'))->update(['alkohol' => false, 'alkohol_source' => 'manuell']);
    $wasser = $this->makeGp($this->rootTeam, 'Mineralwasser');            // ohne Aussage
    ($this->artikel)($wasser, false, 'X1');

    $sosse = $this->makeRecipe($this->rootTeam, 'Weißweinsoße');
    $this->makeIngredient($sosse, 'Weißwein', $wein);
    $gericht = $this->makeRecipe($this->rootTeam, '[HG] Zander|Weißweinsoße', ['is_sales_recipe' => true]);
    FoodAlchemistRecipeIngredient::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $gericht->id, 'referenced_recipe_id' => $sosse->id,
        'raw_text' => 'Weißweinsoße', 'quantity' => '100', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id, 'position' => 1]);
    $saftGlas = $this->makeRecipe($this->rootTeam, 'Apfelsaft pur', ['is_sales_recipe' => true]);
    $this->makeIngredient($saftGlas, 'Apfelsaft', $saft);
    $schorle = $this->makeRecipe($this->rootTeam, 'Apfelschorle', ['is_sales_recipe' => true]);
    $this->makeIngredient($schorle, 'Apfelsaft', $saft);
    $this->makeIngredient($schorle, 'Mineralwasser', $wasser, '100', 2);

    $svc = app(RecipeRecomputeService::class);
    foreach ([$sosse, $gericht, $saftGlas, $schorle] as $r) {
        $svc->recomputePipeline($r->id, false);
    }
    expect($sosse->fresh()->spec_contains_alcohol)->toBeTrue()
        ->and($gericht->fresh()->spec_contains_alcohol)->toBeTrue()      // über das Sub-Rezept
        ->and($saftGlas->fresh()->spec_contains_alcohol)->toBeFalse()    // alle Zutaten echtes nein
        ->and($schorle->fresh()->spec_contains_alcohol)->toBeNull();     // Wasser unbekannt → nie alkoholfrei

    $agg = app(ConcepterAggregateService::class);
    $usedAlg = [];
    $usedZus = [];
    expect($agg->gerichtCodes($gericht->fresh(), $usedAlg, $usedZus))->toContain('Alk.')
        ->and($agg->gerichtCodes($schorle->fresh(), $usedAlg, $usedZus))->not->toContain('Alk.')
        ->and(collect($agg->kennzeichnungLegende($usedAlg, $usedZus)['zusatzstoffe'])->pluck('label')->all())->toContain('enthält Alkohol');
});

it('Vorbelegungs-Regeln (WG 15): vol-% vor Unterkategorie, Wein/Spirituosen ja, Softdrinks nein, Bier ohne vol-% offen, außerhalb WG 15 nie', function () {
    $r = fn (string $name, string $wg, ?string $pflicht = null, ?string $sub = null) => AlkoholAngabe::ausGp($wg, $pflicht, $name, $sub);

    expect($r('Coca-Cola: Dose 0,33 l', '15', 'Dose 0,33 l', '15.7 Erfrischungsgetränke'))->toBeFalse()
        ->and($r('Weißwein: trocken, zum Kochen', '15', null, '15.1 Wein'))->toBeTrue()                    // Kochwein ohne vol-%
        ->and($r('Weißbier: alkoholfrei, 0,0 vol-%, Glasflasche 0,5 l', '15', '0,0 vol-%, Glasflasche 0,5 l', '15.2 Bier & Cidre'))->toBeFalse()
        ->and($r('Weißbier: Glasflasche 0,5 l', '15', null, '15.2 Bier & Cidre'))->toBeNull()
        ->and($r('Pils: 4,9 vol-%', '15', '4,9 vol-%'))->toBeTrue()
        ->and($r('Rum: 40 Vol%, Flasche 0,7 l', '15'))->toBeTrue()
        ->and($r('Rum-Rosinen: 3 vol-%', '07', null, '07.2 Trockenfrüchte'))->toBeNull()
        ->and(AlkoholAngabe::volProzent('Volumen 2 l'))->toBeNull();
});

it('Vorbelegen: Probelauf zählt nur, --apply schreibt abgeleitet nur in WG 15, manuell bleibt; Koch-Alkoholika nur als Vorschlag', function () {
    $cola = ($this->gp)('Coca-Cola: Dose 0,33 l', '15', null, '15.7 Erfrischungsgetränke');
    $wein = ($this->gp)('Weißwein: trocken, zum Kochen', '15', null, '15.1 Wein');
    $bier = ($this->gp)('Weißbier: Glasflasche 0,5 l', '15', null, '15.2 Bier & Cidre');
    $manuell = tap(($this->gp)('Sekt: trocken', '15', null, '15.1 Wein'))->update(['alkohol' => false, 'alkohol_source' => 'manuell']);
    $kochwein = ($this->gp)('Rotwein: konserviert, zum Kochen', '10');
    $mirin = ($this->gp)('Mirin', '10');
    ($this->gp)('Schweinebauch: frisch', '04');
    ($this->gp)('Rotweinessig', '11');
    ($this->gp)('Rumpsteak: frisch', '04');
    $r = $this->makeRecipe($this->rootTeam, 'Teriyaki');
    $this->makeIngredient($r, 'Mirin', $mirin);

    $this->artisan('foodalchemist:alkohol-vorbelegen')
        ->expectsOutputToContain('Probelauf · ja: 1 · nein: 1 · offen: 1 · manuell (bleibt): 1 · würde ändern: 2')
        ->expectsOutputToContain('Vorschläge außerhalb WG 15 (nur Liste, nicht geschrieben): 2')->assertExitCode(0);
    expect($cola->fresh()->alkohol)->toBeNull();

    $this->artisan('foodalchemist:alkohol-vorbelegen --vorschlaege')
        ->expectsOutputToContain('Rotwein: konserviert, zum Kochen')->expectsOutputToContain('Mirin')
        ->doesntExpectOutputToContain('Schweinebauch')->doesntExpectOutputToContain('Rotweinessig')->doesntExpectOutputToContain('Rumpsteak')
        ->assertExitCode(0);

    $this->artisan('foodalchemist:alkohol-vorbelegen --apply --offen')
        ->expectsOutputToContain('Weißbier')->expectsOutputToContain('Geschrieben · ja: 1 · nein: 1 · offen: 1')->assertExitCode(0);
    expect($cola->fresh()->alkohol)->toBeFalse()->and($cola->fresh()->alkohol_source)->toBe('abgeleitet')
        ->and($wein->fresh()->alkohol)->toBeTrue()
        ->and($bier->fresh()->alkohol)->toBeNull()->and($bier->fresh()->alkohol_source)->toBeNull()
        ->and($manuell->fresh()->alkohol)->toBeFalse()->and($manuell->fresh()->alkohol_source)->toBe('manuell')
        ->and($kochwein->fresh()->alkohol)->toBeNull()->and($mirin->fresh()->alkohol)->toBeNull();

    $this->artisan('foodalchemist:alkohol-vorbelegen')->expectsOutputToContain('würde ändern: 0')->assertExitCode(0);
});

it('MCP gps.PUT/GET: ja | nein | offen für jede Warengruppe, gilt als manuell', function () {
    $mirin = ($this->gp)('Mirin', '10');
    $put = (new GpsPutTool)->execute(['id' => $mirin->id, 'name' => $mirin->name, 'alkohol' => 'ja'], $this->ctx);
    expect($put->success)->toBeTrue((string) ($put->error ?? ''))->and($put->data['alkohol'])->toBe('ja');
    $get = (new GpsGetTool)->execute(['id' => $mirin->id], $this->ctx);
    expect($get->data['alkohol'])->toBeTrue()->and($get->data['alkohol_source'])->toBe('manuell');

    (new GpsPutTool)->execute(['id' => $mirin->id, 'name' => $mirin->name, 'alkohol' => 'offen'], $this->ctx);
    expect($mirin->fresh()->alkohol)->toBeNull()->and($mirin->fresh()->alkohol_source)->toBeNull();
});

it('GP-Modal: Feld Alkohol bei jeder Warengruppe; Speichern setzt manuell', function () {
    expect(Livewire::test(GpModal::class)->call('oeffnen')->set('builder.commodity_group_code', '15')->html())->toContain('data-gp-alkohol')
        ->and(Livewire::test(GpModal::class)->call('oeffnen')->set('builder.commodity_group_code', '10')->html())->toContain('data-gp-alkohol');

    $kochwein = ($this->gp)('Rotwein: konserviert, zum Kochen', '10');
    app(GpNamingService::class)->updateGp($this->rootTeam, $kochwein, ['name' => $kochwein->name, 'alkohol' => '1']);
    expect($kochwein->fresh()->alkohol)->toBeTrue()->and($kochwein->fresh()->alkohol_source)->toBe('manuell');
});
