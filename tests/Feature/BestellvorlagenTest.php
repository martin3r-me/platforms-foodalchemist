<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Bestellvorlagen\Index as VorlagenIndex;
use Platform\FoodAlchemist\Livewire\Orders\Editor as OrdersEditor;
use Platform\FoodAlchemist\Models\FoodAlchemistOrder;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderLine;
use Platform\FoodAlchemist\Models\FoodAlchemistOrderTemplate;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\OrderTemplateService;
use Platform\FoodAlchemist\Services\RecipeRecomputeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 68 · Bestellvorlagen = gespeicherte Bestellrunde: Grundprodukt (Artikel per Lead-Strategie),
 * Rezept (Bedarf aus der Rezeptur, „Musterproduktion"), fester Artikel. Fixture wie OrderServiceTest.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(OrderTemplateService::class);
    $t = $this->rootTeam->id;
    $g = FoodAlchemistVocabEinheit::create(['team_id' => $t, 'slug' => 'g', 'display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1]);

    $this->laOf = [];
    $mkGp = function (string $name, float $preis, string $lieferant) use ($t) {
        $supplier = FoodAlchemistSupplier::firstOrCreate(['team_id' => $t, 'name' => $lieferant]);
        $gp = $this->makeGp($this->rootTeam, $name);
        $la = FoodAlchemistSupplierItem::create(['team_id' => $t, 'supplier_id' => $supplier->id, 'designation' => $name . ' 1kg',
            'article_number' => 'ART-' . strtoupper(substr($name, 0, 3)), 'qty' => 1.0, 'unit_code' => 'kg', 'packaging_unit' => 'Sack']);
        FoodAlchemistSupplierItemStructure::create(['team_id' => $t, 'supplier_item_id' => $la->id, 'gp_id' => $gp->id]);
        FoodAlchemistPrice::create(['team_id' => $t, 'supplier_item_id' => $la->id, 'price' => $preis, 'status' => '0']);
        $gp->update(['lead_la_supplier_item_id' => $la->id]);
        $this->laOf[$name] = $la;

        return $gp->refresh();
    };
    $this->mehl = $mkGp('Mehl', 2.00, 'Chefs');
    $this->zucker = $mkGp('Zucker', 1.00, 'Chefs');
    $this->butter = $mkGp('Butter', 12.00, 'Hanos');

    $sauce = FoodAlchemistRecipe::create(['team_id' => $t, 'recipe_key' => 'vanillesauce', 'name' => 'Vanillesauce', 'status' => 'approved', 'is_sales_recipe' => false, 'yield_kg' => 1.0]);
    $sauce->ingredients()->create(['team_id' => $t, 'position' => 0, 'gp_id' => $this->zucker->id, 'raw_text' => 'Zucker', 'quantity' => 500, 'unit_vocab_id' => $g->id]);
    $sauce->ingredients()->create(['team_id' => $t, 'position' => 1, 'gp_id' => $this->butter->id, 'raw_text' => 'Butter', 'quantity' => 500, 'unit_vocab_id' => $g->id]);
    $this->kuchen = FoodAlchemistRecipe::create(['team_id' => $t, 'recipe_key' => 'kuchen', 'name' => 'DES: Kuchen', 'status' => 'approved', 'is_sales_recipe' => true, 'sales_net' => 3.50, 'sales_unit_count' => 10]);
    $this->kuchen->ingredients()->create(['team_id' => $t, 'position' => 0, 'gp_id' => $this->mehl->id, 'raw_text' => 'Mehl', 'quantity' => 1000, 'unit_vocab_id' => $g->id]);
    $this->kuchen->ingredients()->create(['team_id' => $t, 'position' => 1, 'referenced_recipe_id' => $sauce->id, 'raw_text' => 'Vanillesauce', 'quantity' => 150, 'unit_vocab_id' => $g->id]);
    $rc = app(RecipeRecomputeService::class);
    $rc->recomputePipeline($sauce->id);
    $rc->recomputePipeline($this->kuchen->id);
});

it('Positionen: Grundprodukt, Rezept (Standard-Einheit Portionen), fester Artikel; gleiche Quelle wird überschrieben', function () {
    $v = $this->svc->anlegen($this->rootTeam, ['name' => 'Montag'], $this->user->id);
    $this->svc->positionSetzen($this->rootTeam, $v->id, 'gp', $this->butter->id, '2,5');
    $this->svc->positionSetzen($this->rootTeam, $v->id, 'recipe', $this->kuchen->id, 100);
    $this->svc->positionSetzen($this->rootTeam, $v->id, 'supplier_item', $this->laOf['Mehl']->id, 3);
    $this->svc->positionSetzen($this->rootTeam, $v->id, 'gp', $this->butter->id, 4);   // überschreibt

    $d = $this->svc->detail($this->rootTeam, $v->id);
    expect($d->lines)->toHaveCount(3)
        ->and($d->lines->map(fn ($l) => [$l->type, (float) $l->qty, $l->unit])->all())->toBe([['gp', 4.0, 'kg'], ['recipe', 100.0, 'portions'], ['supplier_item', 3.0, 'gebinde']]);

    expect(fn () => $this->svc->positionSetzen($this->rootTeam, $v->id, 'gp', $this->butter->id, 1, 'portions'))->toThrow(\RuntimeException::class, 'passt nicht')
        ->and(fn () => $this->svc->positionSetzen($this->rootTeam, $v->id, 'gp', 999999, 1))->toThrow(\RuntimeException::class, 'nicht gefunden')
        ->and(fn () => $this->svc->detail($this->childA, $v->id))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('Vorschau und Anwenden laufen über die Bestellrunde: Musterproduktion + Grundprodukt → Entwürfe je Lieferant', function () {
    $v = $this->svc->anlegen($this->rootTeam, ['name' => 'Kuchentag']);
    $this->svc->positionSetzen($this->rootTeam, $v->id, 'recipe', $this->kuchen->id, 100);
    $butter = $this->svc->positionSetzen($this->rootTeam, $v->id, 'gp', $this->butter->id, 2);

    $p = $this->svc->vorschau($this->rootTeam, $v->id, '2026-11-02');
    expect(FoodAlchemistOrder::count())->toBe(0)
        ->and(collect($p['orders_preview'])->pluck('supplier')->sort()->values()->all())->toBe(['Chefs', 'Hanos']);

    // Butter-Position auf 0 → ausgelassen; nur der Rezeptbedarf
    $r = $this->svc->anwenden($this->rootTeam, $v->id, '2026-11-02', [$butter->id => 0], null, $this->user->id);
    expect($r['orders'])->toHaveCount(2);
    $hanos = FoodAlchemistOrder::whereHas('supplier', fn ($q) => $q->where('name', 'Hanos'))->first();
    // 100 Portionen = 10 Ansätze × 150 g Sauce = 1,5 kg Sauce → 0,75 kg Butter → 1 Sack à 12 €.
    // Mit der (ausgelassenen) Butter-Position wären es 2 Säcke mehr.
    expect((float) $hanos->total_net)->toBe(12.0)
        ->and($hanos->desired_delivery_date->toDateString())->toBe('2026-11-02')
        ->and(FoodAlchemistOrderTemplate::find($v->id)->last_used_at)->not->toBeNull();
});

it('Aus Bestellung: GP-Zeile wird Grundprodukt in kg, Zeile ohne GP fester Artikel', function () {
    $ohneGp = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $this->laOf['Mehl']->supplier_id,
        'designation' => 'Backpapier', 'article_number' => 'BP', 'qty' => 1, 'unit_code' => 'Stk']);
    FoodAlchemistPrice::create(['team_id' => $this->rootTeam->id, 'supplier_item_id' => $ohneGp->id, 'price' => 5, 'status' => '0']);
    $orders = app(\Platform\FoodAlchemist\Services\OrderService::class);
    $l1 = $orders->addManualLine($this->rootTeam, $this->laOf['Mehl']->id, 6);
    $orders->addManualLine($this->rootTeam, $ohneGp->id, 2);

    $v = $this->svc->ausBestellung($this->rootTeam, (int) $l1->order_id, 'Wochenbedarf');
    $pos = $this->svc->detail($this->rootTeam, $v->id)->lines->map(fn ($l) => [$l->type, (float) $l->qty, $l->unit])->sortBy(0)->values()->all();
    expect($pos)->toBe([['gp', 6.0, 'kg'], ['supplier_item', 2.0, 'gebinde']]);
});

it('MCP: order_templates POST (Positionen) → GET mit Vorschau → PUT → APPLY → DELETE', function () {
    $reg = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);
    $post = $reg->get('foodalchemist.order_templates.POST')->execute(['name' => 'Molkerei', 'bestelltag' => 1, 'positionen' => [
        ['type' => 'gp', 'id' => $this->butter->id, 'menge' => 10, 'einheit' => 'kg'],
    ]], $ctx);
    expect($post->success)->toBeTrue()->and($post->data['positionen'])->toBe(1);
    $id = $post->data['template_id'];

    $get = $reg->get('foodalchemist.order_templates.GET')->execute(['template_id' => $id, 'vorschau' => true, 'liefertag' => '2026-11-03'], $ctx);
    expect($get->data['bestelltag'])->toBe(1)
        ->and($get->data['vorschau']['lieferanten'][0])->toMatchArray(['lieferant' => 'Hanos', 'summe' => 120.0]);

    $put = $reg->get('foodalchemist.order_templates.PUT')->execute(['template_id' => $id,
        'positionen_aendern' => [['line_id' => $get->data['positionen'][0]['line_id'], 'menge' => 5]],
        'positionen_setzen' => [['type' => 'recipe', 'id' => $this->kuchen->id, 'menge' => 10]]], $ctx);
    expect($put->success)->toBeTrue()->and($put->data['positionen'])->toBe(2);
    expect($reg->get('foodalchemist.order_templates.PUT')->execute(['template_id' => $id, 'positionen_entfernen' => [999999]], $ctx)->success)->toBeFalse();

    $apply = $reg->get('foodalchemist.order_templates.APPLY')->execute(['template_id' => $id, 'liefertag' => '2026-11-03'], $ctx);
    expect($apply->success)->toBeTrue()->and($apply->data['bestellungen'])->not->toBeEmpty()
        ->and(FoodAlchemistOrder::where('status', 'draft')->count())->toBeGreaterThan(0);

    $fremd = new ToolContext($this->makeUser($this->childA), $this->childA);
    expect($reg->get('foodalchemist.order_templates.GET')->execute(['template_id' => $id], $fremd)->success)->toBeFalse()
        ->and($reg->get('foodalchemist.order_templates.DELETE')->execute(['template_id' => $id], $ctx)->success)->toBeTrue();
});

it('Oberfläche: Vorlagen-Seite anlegen, Positionen per Suche, Vorschau; Bestellrunde: Vorlage einfügen + Arbeitsstand als Vorlage', function () {
    $idx = Livewire::test(VorlagenIndex::class)
        ->set('neuName', 'Montag Molkerei')->call('anlegen')->assertSet('fehler', null)
        ->assertDispatched('vorlage-editor.oeffnen');
    // Spec 73: die Vorlage selbst wird im Editor bearbeitet
    $lw = Livewire::test(\Platform\FoodAlchemist\Livewire\Bestellvorlagen\Editor::class)
        ->call('oeffnenVorlage', $idx->get('vorlageId'), true)
        ->set('suchArt', 'gp')->set('suche', 'Butt')->assertSee('Butter')
        ->call('positionHinzu', $this->butter->id)
        ->set('suchArt', 'recipe')->set('suche', 'Kuch')->call('positionHinzu', $this->kuchen->id)
        ->assertSee('Gericht — Bedarf aus der Rezeptur')
        ->call('vorschau')->assertSee('Hanos')->assertSee('Chefs');
    $vid = $lw->get('vorlageId');
    $lw->call('bestellen')->assertDispatched('orders-editor.vorlage');

    $ed = Livewire::test(OrdersEditor::class)
        ->call('oeffnenVorlage', $vid, '2026-11-05')
        ->assertSet('formDeliveryDate', '2026-11-05');
    expect($ed->get('cockpitSources'))->toHaveCount(2)
        ->and(collect($ed->get('cockpitSources'))->pluck('type')->all())->toBe(['gp', 'recipe']);

    $ed->set('vorlageName', 'Kopie')->call('cockpitAlsVorlage')->assertSet('fehler', null);
    expect(FoodAlchemistOrderTemplate::where('name', 'Kopie')->first()->lines()->count())->toBe(2);

    $ed->set('vorlageWahl', (string) $vid)->call('cockpitVorlageEinfuegen');
    expect($ed->get('cockpitSources'))->toHaveCount(4);   // Vorlagen kombinierbar
});
