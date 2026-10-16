<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistWarengruppeSubkategorie;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Praxisfall io: LA „Delice Schokoladen-Mousse mit Cashew-Creme" (Achenbach, 21099; TK, 42 Tage tiefgekühlt, 660 g). „Felder vorschlagen" setzte
 * „Riegel" in die Hauptzutat, Zustand „frisch" trotz TK, Form doppelt zur Hauptzutat, keine Unterkategorie in WG 13.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    config(['foodalchemist.bearbeitungssperre' => false]);
    FoodAlchemistLookupWarengruppe::create(['team_id' => $this->rootTeam->id, 'code' => '13', 'name' => '13 Convenience & Komponenten', 'sort_order' => 13]);
    FoodAlchemistWarengruppeSubkategorie::create(['team_id' => $this->rootTeam->id, 'commodity_group_code' => '13', 'name' => '13.5 Desserts & Süßspeisen', 'position' => 5]);
    $lief = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Achenbach']);
    $this->la = fn (array $extra = []) => FoodAlchemistSupplierItem::create($extra + ['team_id' => $this->rootTeam->id, 'supplier_id' => $lief->id, 'article_number' => '21099',
        'designation' => 'Delice Schokoladen-Mousse mit Cashew-Creme', 'ingredients_supplier' => 'Schlag-Creme, Haferkeks, Sojadrink, Cashew-Creme, Schokolade',
        'qty' => 0.66, 'unit_code' => 'kg', 'packaging_unit' => 'Kunststoffform', 'shelf_life_frozen_days' => 42]);
    $this->ki = function (array $werte) {
        $this->mock(AiGatewayService::class, function ($mock) use ($werte) {
            $mock->shouldReceive('propose')->andReturnUsing(fn ($key, $kontext = []) => new AiProposal($werte, 0.8, 'Mock', [], $key));
        });
    };
    $this->vorschlag = ['hauptzutat' => 'Riegel Schokoladenmousse mit Cashew-Creme', 'condition' => 'frisch', 'processing' => 'verzehrfertig', 'form' => 'Mousse',
        'commodity_group_code' => '13', 'sub_category' => '13.5 Desserts und Süßspeisen'];
});

it('★ Praxisfall: Hauptzutat ohne erfundenes „Riegel", Zustand TK aus der Haltbarkeit, Form-Dublette leer, Unterkategorie in WG 13', function () {
    ($this->ki)($this->vorschlag);

    Livewire::test(GpModal::class)->call('oeffnen', null, ($this->la)()->id)->set('kiRohtext', 'Delice Schokoladen-Mousse mit Cashew-Creme')->call('kiVorschlagNaming')
        ->assertSet('builder.hauptzutat', 'Schokoladenmousse mit Cashew-Creme')
        ->assertSet('builder.condition', 'TK')
        ->assertSet('builder.form', '')
        ->assertSet('builder.processing', 'verzehrfertig')
        ->assertSet('builder.commodity_group_code', '13')
        ->assertSet('builder.sub_category', '13.5 Desserts & Süßspeisen')
        ->assertSet('hinweis', fn ($h) => str_contains((string) $h, 'Riegel'));
});

it('★ „Riegel" ist eine echte Form: als Form bleibt es stehen (auch ohne Beleg im Text), nur in der Hauptzutat nicht', function () {
    ($this->ki)(['hauptzutat' => 'Schokoladenmousse', 'condition' => 'TK', 'form' => 'Riegel'] + $this->vorschlag);

    Livewire::test(GpModal::class)->call('oeffnen', null, ($this->la)()->id)->set('kiRohtext', 'x')->call('kiVorschlagNaming')
        ->assertSet('builder.hauptzutat', 'Schokoladenmousse')->assertSet('builder.form', 'Riegel');
});

it('★ Zustand nur hart bei Eindeutigkeit: gekühlt allein, zwei Lagerarten oder keine Angabe überlassen die KI', function () {
    $n = app(GpNamingService::class);
    expect($n->zustandAusHaltbarkeit(null, 42, null))->toBe('TK')->and($n->zustandAusHaltbarkeit(null, null, 365))->toBe('trocken')
        ->and($n->zustandAusHaltbarkeit(9, null, null))->toBeNull()->and($n->zustandAusHaltbarkeit(9, 42, null))->toBeNull()
        ->and($n->zustandAusHaltbarkeit(null, null, null))->toBeNull()->and($n->zustandAusHaltbarkeit(null, 0, null))->toBeNull();

    ($this->ki)($this->vorschlag);
    $la = ($this->la)(['shelf_life_frozen_days' => null, 'shelf_life_chilled_days' => 9]);     // nur gekühlt: KI-Wert „frisch" bleibt
    Livewire::test(GpModal::class)->call('oeffnen', null, $la->id)->set('kiRohtext', 'x')->call('kiVorschlagNaming')->assertSet('builder.condition', 'frisch');
});

it('Halluzinations-Guard: nur belegte Kernwörter, höchstens 3 Inhaltswörter, GP-Vokabular erlaubt, Verbinder nie am Rand', function () {
    $n = app(GpNamingService::class);
    $q = ['Delice Schokoladen-Mousse mit Cashew-Creme', 'Schlag-Creme, Haferkeks, Sojadrink'];

    expect($n->bereinigeHauptzutat('Riegel Schokoladenmousse', $q))->toBe(['wert' => 'Schokoladenmousse', 'entfernt' => ['Riegel']])
        ->and($n->bereinigeHauptzutat('Schokoladenmousse mit', $q)['wert'])->toBe('Schokoladenmousse')
        ->and($n->bereinigeHauptzutat('Schokoladenmousse Haferkeks Sojadrink Cashew-Creme', $q)['wert'])->toBe('Schokoladenmousse Haferkeks Sojadrink');
    $this->makeGp($this->rootTeam, 'Pralinen: TK')->update(['main_ingredient_slug' => 'praline']);
    expect($n->bereinigeHauptzutat('Praline', $q)['wert'])->toBe('Praline');            // nicht im Text, aber im GP-Vokabular
});

it('★ Dublette: Form oder Verarbeitung, die schon in der Hauptzutat steckt, entfällt; echte Formen bleiben', function () {
    $n = app(GpNamingService::class);
    expect($n->dedupliziereMitHauptzutat('Schokoladenmousse', 'Mousse'))->toBe('')->and($n->dedupliziereMitHauptzutat('Schokoladenmousse', 'Riegel'))->toBe('Riegel')
        ->and($n->dedupliziereMitHauptzutat('Salat', ''))->toBe('');
});

it('★ Warnung §7.1: „Gewicht oder Portion" gleich der Gebindemenge des LA (660 g) warnt, eine echte Portion nicht', function () {
    ($this->ki)($this->vorschlag);
    $la = ($this->la)();

    Livewire::test(GpModal::class)->call('oeffnen', null, $la->id)->set('builder.hauptzutat', 'Schokoladenmousse')->set('builder.portion', '660 g')
        ->assertSee('entspricht der Gebindemenge')
        ->set('builder.portion', '0,66 kg')->assertSee('entspricht der Gebindemenge')
        ->set('builder.portion', '120 g')->assertDontSee('entspricht der Gebindemenge');
});

// ── Zusatz im Namen (Bio/Vegan/Glutenfrei/Laktosefrei): nur auf ausdrückliche Quellaussage ──

it('★ Zusatz: ausdrückliche Aussagen setzen ihn (LA-Merkmal, Kontrollnummer, Text), nichts sonst', function () {
    $n = app(GpNamingService::class);
    $z = fn (array $q) => $n->zusatzAusQuellen($q)['flags'];

    expect($z(['is_organic' => 1]))->toMatchArray(['bio' => true, 'vegan' => false])
        ->and($z(['bio_kontrollnummer' => 'DE-ÖKO-001'])['bio'])->toBeTrue()
        ->and($z(['zutaten' => 'Zutaten: Hafer (aus kontrolliert biologischem Anbau), DE-ÖKO-006'])['bio'])->toBeTrue()
        ->and($z(['bezeichnung' => 'Mousse vegan 660 g'])['vegan'])->toBeTrue()
        ->and($z(['marketing_name' => 'Glutenfreier Kuchen glutenfrei'])['glutenfrei'])->toBeTrue()
        ->and($z(['zutaten' => 'laktosefrei, Sojadrink'])['laktosefrei'])->toBeTrue()
        ->and($z(['is_vegan' => 1])['vegan'])->toBeTrue();
    // Gegenproben: keine Aussage, keine Zusätze; „nicht vegan"/„Bionade" zählen nicht
    expect($z(['bezeichnung' => 'Schokoladenmousse', 'zutaten' => 'Schlag-Creme, Haferkeks']))->toBe(['bio' => false, 'vegan' => false, 'glutenfrei' => false, 'laktosefrei' => false])
        ->and($z(['bezeichnung' => 'Mousse, nicht vegan'])['vegan'])->toBeFalse()
        ->and($z(['bezeichnung' => 'Bionade Holunder'])['bio'])->toBeFalse();
});

it('★ Zusatz nie aus Allergenen abgeleitet: Milch „Spuren" und kein Gluten angegeben ergeben weder laktosefrei noch glutenfrei noch vegan', function () {
    $n = app(GpNamingService::class);
    $r = $n->zusatzAusQuellen(['bezeichnung' => 'Schokoladenmousse', 'allergene' => ['milk' => 'spuren', 'gluten' => null, 'eggs' => 'nicht_enthalten']]);

    expect($r['flags'])->toBe(['bio' => false, 'vegan' => false, 'glutenfrei' => false, 'laktosefrei' => false])->and($r['hinweise'])->toBe([]);
});

it('★ Zusatz Widerspruch: vegan behauptet, aber Milch enthalten → nicht gesetzt, Hinweis; glutenfrei trotz Gluten ebenso', function () {
    $n = app(GpNamingService::class);
    $r = $n->zusatzAusQuellen(['bezeichnung' => 'Mousse vegan glutenfrei', 'allergene' => ['milk' => 'enthalten', 'gluten' => 'enthalten']]);

    expect($r['flags']['vegan'])->toBeFalse()->and($r['flags']['glutenfrei'])->toBeFalse()->and($r['widerspruch'])->toBe(['vegan', 'glutenfrei'])->and(implode(' ', $r['hinweise']))->toContain('Milch')->toContain('Gluten');
    // laktosefreie Milchprodukte enthalten Milch: kein Widerspruch
    expect($n->zusatzAusQuellen(['bezeichnung' => 'Laktosefreie Sahne', 'allergene' => ['milk' => 'enthalten']])['flags']['laktosefrei'])->toBeTrue();
});

it('★ Dialog: Mousse mit Milch „Spuren" ohne „vegan" im Text bekommt keinen Vegan-Zusatz; mit „vegan" im Zutatentext schon', function () {
    ($this->ki)($this->vorschlag);
    $ohne = ($this->la)();
    \Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $ohne->id, 'allergen_milk' => 'spuren']);
    Livewire::test(GpModal::class)->call('oeffnen', null, $ohne->id)->set('kiRohtext', 'x')->call('kiVorschlagNaming')
        ->assertSet('builder.vegan', false)->assertSet('builder.laktosefrei', false)->assertSet('builder.glutenfrei', false);

    $mit = ($this->la)(['ingredients_supplier' => 'Schlag-Creme (vegan), Haferkeks, Sojadrink']);
    Livewire::test(GpModal::class)->call('oeffnen', null, $mit->id)->set('kiRohtext', 'x')->call('kiVorschlagNaming')->assertSet('builder.vegan', true);

    $konflikt = ($this->la)(['ingredients_supplier' => 'vegan']);
    \Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $konflikt->id, 'allergen_eggs' => 'enthalten']);
    Livewire::test(GpModal::class)->call('oeffnen', null, $konflikt->id)->set('kiRohtext', 'x')->call('kiVorschlagNaming')
        ->assertSet('builder.vegan', false)->assertSet('hinweis', fn ($h) => str_contains((string) $h, 'Eier'));
});

it('Zusatz: eine Handeingabe vor dem Vorschlag wird nie abgeschaltet', function () {
    ($this->ki)($this->vorschlag);
    Livewire::test(GpModal::class)->call('oeffnen', null, ($this->la)()->id)->set('builder.bio', true)->set('kiRohtext', 'x')->call('kiVorschlagNaming')
        ->assertSet('builder.bio', true);
});
