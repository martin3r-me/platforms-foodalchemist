<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistWarengruppeSubkategorie;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\AiProposal;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * „Grundprodukt anlegen" aus einem Lieferantenartikel (Dominique, Beispiel »Salat, Bella Caio Tk«):
 * „unbekannt" ist kein Wert und steht nie in Name oder Kennung; die KI bekommt den vollen LA-Kontext;
 * die Unterkategorie wird gegen die Taxonomie abgeglichen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
    config(['foodalchemist.bearbeitungssperre' => false]);
    $this->naming = fn () => app(GpNamingService::class);

    $this->kontexte = [];
    $this->ki = function (array $werte) {
        $this->mock(AiGatewayService::class, function ($mock) use ($werte) {
            $mock->shouldReceive('propose')->andReturnUsing(function ($key, $kontext = []) use ($werte) {
                $this->kontexte[] = $kontext;

                return new AiProposal($werte, 0.8, 'Mock', [], $key);
            });
        });
    };
    FoodAlchemistLookupWarengruppe::create(['team_id' => $this->rootTeam->id, 'code' => '07', 'name' => '07 Gemuese', 'sort_order' => 7]);
    FoodAlchemistWarengruppeSubkategorie::create(['team_id' => $this->rootTeam->id, 'commodity_group_code' => '07', 'name' => '7.2 Salate & Blattgemüse', 'position' => 2]);

    $lief = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => "D'Arta"]);
    $this->la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $lief->id, 'article_number' => 'P15003F5E',
        'designation' => 'Salat, Bella Caio Tk', 'qty' => 1.25, 'unit_code' => 'kg', 'packaging_unit' => 'KA']);
});

it('★ „unbekannt" & Co. werden zu leer — nie im Namen, nie in der Kennung', function () {
    foreach (['unbekannt', 'Unbekannt', ' UNBEKANNT ', 'n/a', 'k.A.', 'unknown', '-', '?', '(unbekannt)', null, ''] as $roh) {
        expect(($this->naming)()->bereinigeFeld($roh))->toBe('', var_export($roh, true));
    }
    expect(($this->naming)()->bereinigeFeld(' Würfel 10 mm '))->toBe('Würfel 10 mm')->and(($this->naming)()->bereinigeFeld('ganz'))->toBe('ganz');

    $n = ($this->naming)();
    $eingabe = ['hauptzutat' => 'Salat', 'condition' => 'TK', 'processing' => 'unbekannt', 'form' => 'unbekannt'];
    expect($n->renderGpName($eingabe))->toBe('Salat: TK')
        ->and($n->buildGpKey('salat', 'unbekannt', 'unbekannt'))->toBe('salat||');
});

it('★ createGp: Platzhalter-Eingaben landen weder im Namen noch im gp_key; ein Name mit „unbekannt" wird abgewiesen', function () {
    $gp = ($this->naming)()->createGp($this->rootTeam, ['hauptzutat' => 'Salatmischung', 'condition' => 'TK', 'processing' => 'unbekannt', 'form' => 'unbekannt']);
    expect($gp->name)->toBe('Salatmischung: TK')->and($gp->gp_key)->toBe('salatmischung||')->and($gp->processing)->toBeNull()->and($gp->form)->toBeNull();

    expect(fn () => ($this->naming)()->createGp($this->rootTeam, ['hauptzutat' => 'Salat', 'condition' => 'TK', 'name' => 'Salat: TK, unbekannt']))
        ->toThrow(RuntimeException::class, 'unbekannt');
    expect(FoodAlchemistGp::where('name', 'like', '%unbekannt%')->count())->toBe(0);
});

it('★ „Felder vorschlagen": unbekannt aus der KI bleibt leer, Sorten-/Markenname raus, Unterkategorie wird gesetzt', function () {
    ($this->ki)(['hauptzutat' => 'Salatmischung', 'condition' => 'TK', 'processing' => 'unbekannt', 'form' => 'unbekannt',
        'commodity_group_code' => '07', 'sub_category' => '7.2 salate und blattgemuese']);   // andere Schreibweise: „und", ohne Umlaut

    $c = Livewire::test(GpModal::class)->call('oeffnen', null, $this->la->id)
        ->set('kiRohtext', 'Salat, Bella Caio Tk')->call('kiVorschlagNaming')
        ->assertSet('builder.hauptzutat', 'Salatmischung')
        ->assertSet('builder.condition', 'TK')
        ->assertSet('builder.processing', '')
        ->assertSet('builder.form', '')
        ->assertSet('builder.commodity_group_code', '07')
        ->assertSet('builder.sub_category', '7.2 Salate & Blattgemüse');
    $c->assertDontSeeHtml('unbekannt')->assertSeeHtml('Salatmischung: TK');
});

it('★ „Felder vorschlagen": die KI bekommt den vollen LA-Kontext (Marketingname, Zutaten, Gebinde-Hinweis, Allergene, ähnliche Artikel mit GP)', function () {
    $this->la->update(['marketing_name' => 'Bella Ciao Salatmischung', 'ingredients_supplier' => 'Eisbergsalat, Frisee, Radicchio']);
    FoodAlchemistItemAllergen::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->la->id, 'allergen_celery' => 'spuren']);
    $vorbild = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->la->supplier_id, 'designation' => 'Salat Gemischt Tk 1kg']);
    $gp = $this->makeGp($this->rootTeam, 'Salatmischung: TK');
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $vorbild->id, 'gp_id' => $gp->id]);
    ($this->ki)(['hauptzutat' => 'Salatmischung', 'condition' => 'TK']);

    Livewire::test(GpModal::class)->call('oeffnen', null, $this->la->id)->set('kiRohtext', 'Salat, Bella Caio Tk')->call('kiVorschlagNaming');

    $q = $this->kontexte[0]['quell_lieferantenartikel'];
    expect($q['marketing_name'])->toBe('Bella Ciao Salatmischung')->and($q['zutatenangabe'])->toBe('Eisbergsalat, Frisee, Radicchio')
        ->and($q['gebinde_hinweis'])->toMatchArray(['menge' => 1.25, 'einheit' => 'kg'])
        ->and($q['allergene'])->toBe(['Sellerie (spuren)'])
        ->and($q['aehnliche_artikel_mit_gp'])->toBe([['bezeichnung' => 'Salat Gemischt Tk 1kg', 'gp' => 'Salatmischung: TK']]);
});

it('Prompt gp.suggest: Leerwert-Regel, Marken-/Sortenname und Unterkategorie stehen in der Aufgabe', function () {
    $task = config('foodalchemist.prompts')['gp.suggest']['task'];
    expect($task)->toContain('UNBEKANNT BLEIBT LEER')->toContain('Fantasienamen')->toContain('sub_category immer an')->toContain('aehnliche_artikel_mit_gp');
});
