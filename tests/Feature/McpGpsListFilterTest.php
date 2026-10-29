<?php

use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\GpsListTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP gps.LIST für Bestandsarbeit per MCP (Kurator 29.10.): Filter nach Warengruppe, Unterkategorie, Status, Marke offen, Rezepturware,
 * ohne Lead — und felder="voll". Sicht und Paging wie bisher.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Chefs Culinar']);
    $la = FoodAlchemistSupplierItem::create(['team_id' => $this->rootTeam->id, 'supplier_id' => $sup->id, 'designation' => 'HT Kalbsjus 1 kg', 'qty' => 1, 'unit_code' => 'kg']);
    $gp = fn (string $name, array $extra = []) => FoodAlchemistGp::create(array_merge(['team_id' => $this->rootTeam->id, 'name' => $name,
        'gp_key' => 'gl-' . bin2hex(random_bytes(4)) . '||', 'status' => 'approved', 'commodity_group_code' => '13'], $extra));
    $this->jus = $gp('Kalbsjus / (Hi Taste)', ['sub_category' => '13.3 Saucen & Fonds', 'rezepturware' => true, 'zusatz' => 'Hi Taste', 'lead_la_supplier_item_id' => $la->id, 'condition' => 'konserviert']);
    $this->offen = $gp('Bratensauce', ['sub_category' => '13.3 Saucen & Fonds', 'rezepturware' => true, 'marke_offen' => true]);
    $this->apfel = $gp('Apfel: frisch', ['commodity_group_code' => '02', 'rezepturware' => false, 'status' => 'tentative']);
    $this->ungeklaert = $gp('Pesto', ['commodity_group_code' => '13']);
    $this->fremd = FoodAlchemistGp::create(['team_id' => $this->childB->id, 'name' => 'Fremd-GP', 'gp_key' => 'gl-fremd||', 'status' => 'approved', 'commodity_group_code' => '13']);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    $this->ids = fn (array $a) => collect(app(GpsListTool::class)->execute($a, $this->ctx)->data['gps'])->pluck('id')->sort()->values()->all();
    $this->sortiert = fn (...$gps) => collect($gps)->pluck('id')->sort()->values()->all();
});

it('filtert je Feld: Warengruppe, Unterkategorie, Status, Marke offen, Rezepturware (ja/nein/offen), ohne Lead — kombinierbar', function () {
    expect(($this->ids)(['commodity_group_code' => '02']))->toBe(($this->sortiert)($this->apfel))
        ->and(($this->ids)(['sub_category' => '13.3 Saucen & Fonds']))->toBe(($this->sortiert)($this->jus, $this->offen))
        ->and(($this->ids)(['status' => 'tentative']))->toBe(($this->sortiert)($this->apfel))
        ->and(($this->ids)(['marke_offen' => true]))->toBe(($this->sortiert)($this->offen))
        ->and(($this->ids)(['marke_offen' => 'false', 'commodity_group_code' => '13']))->toBe(($this->sortiert)($this->jus, $this->ungeklaert))
        ->and(($this->ids)(['rezepturware' => 'ja']))->toBe(($this->sortiert)($this->jus, $this->offen))
        ->and(($this->ids)(['rezepturware' => 'nein']))->toBe(($this->sortiert)($this->apfel))
        ->and(($this->ids)(['rezepturware' => 'offen']))->toBe(($this->sortiert)($this->ungeklaert))
        ->and(($this->ids)(['ohne_lead' => false]))->toBe(($this->sortiert)($this->jus))
        ->and(($this->ids)(['ohne_lead' => true, 'commodity_group_code' => '13', 'rezepturware' => 'ja']))->toBe(($this->sortiert)($this->offen))
        ->and(($this->ids)([]))->not->toContain($this->fremd->id);   // Sicht unverändert
    expect(app(GpsListTool::class)->execute(['rezepturware' => 'vielleicht'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR');
});

it('felder=voll liefert WG, Unterkategorie, Zustand, Zusatz, Marke offen, Rezepturware und den Lead samt Lieferant; kurz bleibt schmal', function () {
    $voll = collect(app(GpsListTool::class)->execute(['felder' => 'voll', 'sub_category' => '13.3 Saucen & Fonds'], $this->ctx)->data['gps'])->keyBy('id');
    $kurz = app(GpsListTool::class)->execute(['sub_category' => '13.3 Saucen & Fonds'], $this->ctx)->data['gps'][0];

    expect($voll[$this->jus->id])->toMatchArray(['commodity_group_code' => '13', 'sub_category' => '13.3 Saucen & Fonds', 'condition' => 'konserviert',
        'zusatz' => 'Hi Taste', 'marke_offen' => false, 'rezepturware' => true, 'lead_la_supplier_item_id' => $this->jus->lead_la_supplier_item_id,
        'lead_la' => ['designation' => 'HT Kalbsjus 1 kg', 'lieferant' => 'Chefs Culinar']])
        ->and($voll[$this->offen->id]['lead_la'])->toBeNull()
        ->and(array_keys($kurz))->toBe(['id', 'name', 'status', 'main_ingredient_slug']);
});
