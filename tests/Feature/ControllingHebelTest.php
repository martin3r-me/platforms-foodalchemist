<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Preisvergleich;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\VkFreigabe;
use Platform\FoodAlchemist\Models\FoodAlchemistPrice;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Models\FoodAlchemistVkPriceSnapshot;
use Platform\FoodAlchemist\Services\SimulationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 32 · C2 — die Hebel der Werkbank.
 *
 * Der Kern der Spec ist, dass Befund und Handlung am selben Ort liegen. Diese Datei prüft
 * die Handlungen: Bezugsquelle umstellen, Verkaufspreise freigeben, Lieferant-Szenario.
 * Jede geht über einen bestehenden Service — geprüft wird also, dass die Fläche ihn richtig
 * ruft und dass sie nichts anfasst, was ihr nicht gehört.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->childA);
    $this->actingAs($this->user);

    $this->teuer = FoodAlchemistSupplier::create([
        'team_id' => $this->childA->id, 'name' => 'Teuer GmbH', 'status' => 'aktiv',
    ]);
    $this->guenstig = FoodAlchemistSupplier::create([
        'team_id' => $this->childA->id, 'name' => 'Günstig KG', 'status' => 'aktiv',
    ]);

    $this->gp = $this->makeGp($this->childA, 'Butter');

    $mkLa = function (FoodAlchemistSupplier $s, float $preis) {
        $la = FoodAlchemistSupplierItem::create([
            'team_id' => $this->childA->id, 'supplier_id' => $s->id, 'gp_id' => $this->gp->id,
            'designation' => 'Butter 250 g ' . $s->name, 'unit_code' => 'kg', 'qty' => 1,
        ]);
        FoodAlchemistPrice::create([
            'team_id' => $this->childA->id, 'supplier_item_id' => $la->id,
            'price' => $preis, 'status' => '0',
        ]);
        // Die LA↔GP-Verknüpfung, die LeadLaService prüft, hängt an der Struktur-Tabelle —
        // `supplier_items.gp_id` allein reicht nicht (GL-03 I2). Über das Model, nicht per
        // DB::insert: der UUID-Hook sitzt in `booted()`.
        FoodAlchemistSupplierItemStructure::create([
            'team_id' => $this->childA->id, 'supplier_item_id' => $la->id, 'gp_id' => $this->gp->id,
        ]);

        return $la;
    };

    $this->servierform = FoodAlchemistServierform::firstOrCreate(
        ['code' => 'unbestimmt', 'team_id' => $this->childA->id],
        ['label' => 'Unbestimmt'],
    );

    $this->laTeuer = $mkLa($this->teuer, 12.00);
    $this->laGuenstig = $mkLa($this->guenstig, 7.00);
});

it('stellt die Bezugsquelle auf den günstigsten Artikel um', function () {
    $this->gp->update(['lead_la_supplier_item_id' => $this->laTeuer->id]);

    Livewire::test(Preisvergleich::class)
        ->call('bezugsquelleSetzen', $this->gp->id, $this->laGuenstig->id)
        ->assertSet('fehler', null);

    expect((int) $this->gp->refresh()->lead_la_supplier_item_id)->toBe((int) $this->laGuenstig->id);
});

it('verweigert eine Bezugsquelle, die nicht zum Grundprodukt gehört', function () {
    // Fremder Artikel ohne Verknüpfung — LeadLaService wirft (GL-03 I2), die Fläche fängt das
    // ab und meldet, statt den Editor mit einer Exception zu zerlegen.
    $fremd = FoodAlchemistSupplierItem::create([
        'team_id' => $this->childA->id, 'supplier_id' => $this->guenstig->id,
        'designation' => 'Ganz was anderes', 'unit_code' => 'kg', 'qty' => 1,
    ]);

    Livewire::test(Preisvergleich::class)
        ->call('bezugsquelleSetzen', $this->gp->id, $fremd->id)
        ->assertSet('hinweis', null);

    expect($this->gp->refresh()->lead_la_supplier_item_id)->toBeNull();
});

it('fasst kein Grundprodukt an, das dem Team nicht sichtbar ist', function () {
    // childB ist ein Geschwister — sein GP darf über diese Fläche nicht erreichbar sein.
    $fremdGp = $this->makeGp($this->childB, 'Fremde Butter');

    Livewire::test(Preisvergleich::class)
        ->call('bezugsquelleSetzen', $fremdGp->id, $this->laGuenstig->id)
        ->assertSet('fehler', 'Grundprodukt nicht gefunden.');

    expect($fremdGp->refresh()->lead_la_supplier_item_id)->toBeNull();
});

it('simuliert einen Preisaufschlag über das ganze Sortiment eines Lieferanten', function () {
    $this->gp->update(['lead_la_supplier_item_id' => $this->laTeuer->id]);

    // Scope „lieferant" trifft die GPs, deren LEAD bei diesem Lieferanten liegt …
    $treffer = app(SimulationService::class)->simuliere($this->childA, 'lieferant', (string) $this->teuer->id, 10.0);
    expect($treffer['n_gps'])->toBe(1);

    // … und nicht die, die woanders beziehen. Sonst wäre das Szenario wertlos.
    $daneben = app(SimulationService::class)->simuliere($this->childA, 'lieferant', (string) $this->guenstig->id, 10.0);
    expect($daneben['n_gps'])->toBe(0);
});

it('gibt Verkaufspreise frei und trennt Erstfall von Abweichung', function () {
    $gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->childA->id, 'recipe_key' => 'vk1', 'name' => 'Rührei', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 9.00, 'sales_unit_count' => 1,
    ]);
    $d = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->childA->id, 'recipe_id' => $gericht->id,
        'serving_form_id' => $this->servierform->id, 'sales_net' => 9.00, 'is_standard' => true,
    ]);

    // Vor der ersten Freigabe steht die Darreichung im Erstfall, nicht in „weggelaufen".
    $lw = Livewire::test(VkFreigabe::class)
        ->assertViewHas('neu', fn ($n) => count($n) === 1 && $n[0]['presentation_id'] === $d->id)
        ->assertViewHas('abgedriftet', fn ($a) => $a === []);

    $lw->set('auswahl', [$d->id])->call('freigeben')->assertSet('fehler', null);

    expect(FoodAlchemistVkPriceSnapshot::where('presentation_id', $d->id)->count())->toBe(1)
        ->and((float) FoodAlchemistVkPriceSnapshot::where('presentation_id', $d->id)->value('sales_net'))->toBe(9.0);

    // Danach ist der Erstfall leer — und ohne Preisbewegung meldet auch nichts eine Abweichung.
    Livewire::test(VkFreigabe::class)
        ->assertViewHas('neu', fn ($n) => $n === [])
        ->assertViewHas('abgedriftet', fn ($a) => $a === []);
});

it('meldet eine weggelaufene Freigabe, sobald der Live-Preis die Leitplanke reißt', function () {
    $gericht = FoodAlchemistRecipe::create([
        'team_id' => $this->childA->id, 'recipe_key' => 'vk2', 'name' => 'Suppe', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 10.00, 'sales_unit_count' => 1,
    ]);
    $d = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->childA->id, 'recipe_id' => $gericht->id,
        'serving_form_id' => $this->servierform->id, 'sales_net' => 10.00, 'is_standard' => true,
    ]);

    Livewire::test(VkFreigabe::class)->set('auswahl', [$d->id])->call('freigeben');

    // Live-Preis zieht kräftig an — der freigegebene Stand bleibt, wo er war.
    $d->update(['sales_net' => 15.00]);

    Livewire::test(VkFreigabe::class)
        ->assertViewHas('abgedriftet', fn ($a) => count($a) === 1
            && $a[0]['richtung'] === 'erhoehen'
            && abs($a[0]['published_net'] - 10.0) < 0.01)
        ->assertViewHas('neu', fn ($n) => $n === []);

    // Ohne erneute Freigabe bleibt der veröffentlichte Preis unverändert — das ist der Zweck
    // der ganzen Trennung: kein stiller Preissprung beim Kunden.
    expect((float) FoodAlchemistVkPriceSnapshot::where('presentation_id', $d->id)
        ->orderByDesc('id')->value('sales_net'))->toBe(10.0);
});

it('gibt keine fremden Darreichungen frei', function () {
    $fremdesGericht = FoodAlchemistRecipe::create([
        'team_id' => $this->childB->id, 'recipe_key' => 'vk3', 'name' => 'Fremd', 'status' => 'approved',
        'is_sales_recipe' => true, 'sales_net' => 5.00, 'sales_unit_count' => 1,
    ]);
    $fremd = FoodAlchemistRecipeDarreichung::create([
        'team_id' => $this->childB->id, 'recipe_id' => $fremdesGericht->id,
        'serving_form_id' => $this->servierform->id, 'sales_net' => 5.00, 'is_standard' => true,
    ]);

    Livewire::test(VkFreigabe::class)->set('auswahl', [$fremd->id])->call('freigeben');

    expect(FoodAlchemistVkPriceSnapshot::where('presentation_id', $fremd->id)->count())->toBe(0);
});
