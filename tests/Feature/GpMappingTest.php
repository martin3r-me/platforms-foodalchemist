<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Gps\DetailPanel;
use Platform\FoodAlchemist\Livewire\Suppliers\Index as SupplierIndex;
use Platform\FoodAlchemist\Livewire\Suppliers\ItemModal;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R9: GP-Mapping am LA-Modal (Jarvis: ✨ KI-Vorschlag + zuweisen/lösen) +
 * GP-Panel-Verwendungen (M9-05 GP-Blickwinkel) + ★-Lead-Direktklick.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->gp = $this->makeGp($this->rootTeam, 'Tomatenmark');
    $supplier = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Necta']);
    $this->la = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $supplier->id,
        'designation' => 'Tomatenmark 3-fach 800g', 'qty' => 0.8, 'unit_code' => 'kg',
    ]);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->la->id, 'gp_id' => null]);
});

it('LA-Modal: gpZuweisen verknüpft (Structure + Zähler), gpLoesen entknüpft', function () {
    Livewire::test(ItemModal::class)
        ->call('oeffnen', $this->la->id)
        ->call('gpZuweisen', $this->gp->id)
        ->assertSet('fehler', null);
    expect((int) DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $this->la->id)->value('gp_id'))->toBe($this->gp->id)
        ->and((int) $this->gp->fresh()->n_las_total)->toBe(1);

    Livewire::test(ItemModal::class)
        ->call('oeffnen', $this->la->id)
        ->call('gpLoesen')
        ->assertSet('fehler', null);
    expect(DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $this->la->id)->value('gp_id'))->toBeNull();
});

it('LA-Modal: kiGpVorschlag findet den Fuzzy-Kandidaten (MatchService v1)', function () {
    $c = Livewire::test(ItemModal::class)
        ->call('oeffnen', $this->la->id)
        ->call('kiGpVorschlag');
    $vorschlaege = $c->get('gpVorschlaege');
    expect(collect($vorschlaege)->pluck('gp_id'))->toContain($this->gp->id);

    // Klick auf den Vorschlag weist zu
    $c->call('gpZuweisen', $vorschlaege[0]['gp_id'])->assertSet('fehler', null);
    expect((int) DB::table('foodalchemist_supplier_item_structures')->where('supplier_item_id', $this->la->id)->value('gp_id'))->toBe($vorschlaege[0]['gp_id']);
});

it('LA-Modal startet die GP-Neuanlage mit dem Artikel als LA-first-Quelle', function () {
    Livewire::test(ItemModal::class)
        ->call('oeffnen', $this->la->id)
        ->call('gpNeuAnlegen')
        ->assertDispatched('gp-modal.oeffnen', id: null, laId: $this->la->id, autoSuggest: true)
        ->assertNotDispatched('modal.close');
});

it('LA-first schließt das Artikel-Modal erst nach erfolgreicher GP-Verknüpfung', function () {
    $modal = Livewire::test(ItemModal::class)
        ->call('oeffnen', $this->la->id)
        ->call('gpGespeichert')
        ->assertNotDispatched('modal.close');

    app(LeadLaService::class)->verknuepfen($this->rootTeam, $this->gp, $this->la->id);

    $modal->call('gpGespeichert')
        ->assertDispatched('modal.close', name: 'item-modal');
});

it('Lieferantenseite mountet den GP-Editor als Ziel des LA-first-Events', function () {
    Livewire::test(SupplierIndex::class)
        ->assertSeeHtml('data-gp-speichern-kopf');
});

it('KI-Ersatzvorschlag übergibt ungemappten LA an die atomare GP-Ersatz-Anlage', function () {
    Livewire::test(DetailPanel::class, ['gpId' => $this->gp->id, 'embedded' => true, 'section' => 'ersatz'])
        ->set('ersatzKiVorschlaege', [[
            'kind' => 'supplier_item', 'id' => $this->la->id, 'name' => $this->la->designation,
            'score' => 0.91, 'reason' => 'Gleiche kulinarische Funktion.', 'supplier' => 'Necta',
        ]])
        ->assertSeeHtml('data-ersatz-ki-zeile')
        ->assertSeeHtml('bg-white/60')
        ->assertDontSeeHtml('bg-white/50')
        ->call('ersatzLaAlsGpAnlegen', $this->la->id)
        ->assertDispatched(
            'gp-modal.oeffnen',
            id: null,
            laId: $this->la->id,
            autoSuggest: true,
            equivalentSourceKind: 'gp',
            equivalentSourceId: $this->gp->id,
            equivalentReason: 'Gleiche kulinarische Funktion.',
            equivalentConfidence: 0.91,
        );
});

it('GP-Suche findet einen ungemappten LA auch ohne Structure-Zeile und über die Artikelnummer', function () {
    $ohneStruktur = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id,
        'supplier_id' => $this->la->supplier_id,
        'article_number' => '40909330',
        'designation' => 'BIETENCREME MET DRAGON SOUS VIDE GEGAARD',
    ]);

    $treffer = app(LeadLaService::class)->sucheVerknuepfbare($this->rootTeam, '40909330');

    expect($treffer->pluck('id'))->toContain($ohneStruktur->id);
});

it('LA-Verknüpfung blockiert ein bekannt abweichendes Allergen- oder Zusatzstoffprofil', function () {
    app(LeadLaService::class)->verknuepfen($this->rootTeam, $this->gp, $this->la->id);
    FoodAlchemistItemAllergen::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->la->id,
        'allergen_milk' => 'nicht_enthalten',
    ]);
    FoodAlchemistItemDeclaration::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $this->la->id,
        'with_preservative' => 1,
    ]);

    $neu = FoodAlchemistSupplierItem::create([
        'team_id' => $this->rootTeam->id, 'supplier_id' => $this->la->supplier_id,
        'designation' => 'Tomatenmark mit Milch',
    ]);
    FoodAlchemistItemAllergen::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $neu->id,
        'allergen_milk' => 'enthalten',
    ]);
    FoodAlchemistItemDeclaration::create([
        'team_id' => $this->rootTeam->id, 'supplier_item_id' => $neu->id,
        'with_preservative' => 3,
    ]);

    expect(fn () => app(LeadLaService::class)->verknuepfen($this->rootTeam, $this->gp->fresh(), $neu->id))
        ->toThrow(RuntimeException::class, 'neues GP');
    expect(FoodAlchemistSupplierItemStructure::where('supplier_item_id', $neu->id)->whereNotNull('gp_id')->exists())->toBeFalse();
});

it('GP-Panel: Verwendungen listen Basis- und VK-Rezepte, ★-Lead-Klick setzt den globalen Lead', function () {
    // LA verknüpfen + Rezepte, die das GP nutzen
    Livewire::test(ItemModal::class)->call('oeffnen', $this->la->id)->call('gpZuweisen', $this->gp->id);
    $g = \Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit::create(['team_id' => $this->rootTeam->id, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);
    foreach ([['Fond: Tomate', false], ['HG: Pasta Rosso', true]] as [$name, $vk]) {
        $r = FoodAlchemistRecipe::create([
            'team_id' => $this->rootTeam->id, 'recipe_key' => \Illuminate\Support\Str::slug($name, '_'),
            'name' => $name, 'status' => 'draft', 'is_sales_recipe' => $vk,
        ]);
        DB::table('foodalchemist_recipe_ingredients')->insert([
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'recipe_id' => $r->id, 'gp_id' => $this->gp->id, 'raw_text' => 'Tomatenmark', 'quantity' => 100, 'unit_vocab_id' => $g->id,
            'position' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    Livewire::test(DetailPanel::class, ['gpId' => $this->gp->id])
        ->assertSee('Verwendet in Rezepten (2)')
        ->assertSee('Fond: Tomate')
        ->assertSee('HG: Pasta Rosso')
        ->call('leadSetzen', $this->la->id)
        ->assertSet('fehler', null);
    expect((int) $this->gp->fresh()->lead_la_supplier_item_id)->toBe($this->la->id);
});
