<?php

use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierGroup;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemNonfood;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemStructure;
use Platform\FoodAlchemist\Services\SupplierGroupService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\ArtikelListTool;
use Platform\FoodAlchemist\Tools\ArtikelSearchTool;
use Platform\FoodAlchemist\Tools\SuppliersSearchTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 91 PR 1 (MCP-Filter-Gleichstand): artikel.LIST/SEARCH und suppliers.SEARCH filtern wie der Lieferanten-Browser.
 * Vorher verwarf artikel.LIST supplier_id still (ganzer Katalog), suppliers.SEARCH endete bei 50 ohne Gesamtzahl.
 * Unbekannte Parameter werden weiter ignoriert, aber als `warnungen` gemeldet.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.semantic_search.enabled' => false]);
    app(SupplierGroupService::class)->einrichten(true);
    $t = $this->rootTeam->id;
    $this->hanos = FoodAlchemistSupplier::create(['team_id' => $t, 'name' => 'Hanos', 'group_id' => FoodAlchemistSupplierGroup::where('code', 'LM')->value('id')]);
    $this->kluth = FoodAlchemistSupplier::create(['team_id' => $t, 'name' => 'Kluth', 'rolle' => 'offen']);
    $this->verleih = FoodAlchemistSupplier::create(['team_id' => $t, 'name' => 'Zeltverleih', 'group_id' => FoodAlchemistSupplierGroup::where('code', 'VERLEIH')->value('id')]);
    $la = fn ($sup, string $name, array $extra = []) => FoodAlchemistSupplierItem::create(array_merge(['team_id' => $t, 'supplier_id' => $sup->id,
        'designation' => $name, 'article_number' => 'A-' . bin2hex(random_bytes(3)), 'qty' => 1, 'unit_code' => 'kg'], $extra));
    $this->zander = $la($this->hanos, 'Zanderfilet TK');
    $this->butter = $la($this->hanos, 'Butter Süßrahm');
    $this->alt = $la($this->hanos, 'Zanderfilet frisch', ['is_discontinued' => true]);
    $this->becher = $la($this->hanos, 'Einwegbecher 0,2 l');
    FoodAlchemistSupplierItemNonfood::create(['team_id' => $t, 'supplier_item_id' => $this->becher->id, 'ist_nonfood' => true, 'quelle' => 'manuell']);
    $this->kluthZander = $la($this->kluth, 'Zander ganz');
    $this->gp = FoodAlchemistGp::create(['team_id' => $t, 'name' => 'Zanderfilet: TK', 'gp_key' => 'mf-' . bin2hex(random_bytes(4)) . '||',
        'status' => 'approved', 'commodity_group_code' => '05', 'rezepturware' => true, 'lead_la_supplier_item_id' => $this->zander->id]);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $t, 'supplier_item_id' => $this->zander->id, 'gp_id' => $this->gp->id]);
    FoodAlchemistSupplierItemStructure::create(['team_id' => $t, 'supplier_item_id' => $this->kluthZander->id, 'gp_id' => $this->gp->id]);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    $this->artikel = fn (array $a) => collect(app(ArtikelListTool::class)->execute($a, $this->ctx)->data['artikel'])->pluck('id')->sort()->values()->all();
    $this->ids = fn (...$las) => collect($las)->pluck('id')->sort()->values()->all();
});

it('artikel.LIST filtert wie der Lieferanten-Browser: supplier_id, q, art, only_active — kombinierbar', function () {
    expect(($this->artikel)(['supplier_id' => $this->hanos->id]))->toBe(($this->ids)($this->zander, $this->butter, $this->becher))
        ->and(($this->artikel)(['supplier_id' => $this->kluth->id]))->toBe(($this->ids)($this->kluthZander))
        ->and(($this->artikel)(['q' => 'zander']))->toBe(($this->ids)($this->zander, $this->kluthZander))
        ->and(($this->artikel)(['supplier_id' => $this->hanos->id, 'q' => 'zander filet']))->toBe(($this->ids)($this->zander))
        ->and(($this->artikel)(['supplier_id' => $this->hanos->id, 'only_active' => false, 'q' => 'zander']))->toBe(($this->ids)($this->zander, $this->alt))
        ->and(($this->artikel)(['art' => 'nonfood']))->toBe(($this->ids)($this->becher))
        ->and(($this->artikel)(['supplier_id' => $this->hanos->id, 'art' => 'food']))->toBe(($this->ids)($this->zander, $this->butter));
    expect(app(ArtikelListTool::class)->execute(['art' => 'getraenk'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR');
});

it('artikel.LIST felder=voll liefert Menge, EK, Lieferstatus, GP und Lead-Kennzeichen; kurz bleibt schmal mit supplier_id', function () {
    $voll = collect(app(ArtikelListTool::class)->execute(['felder' => 'voll', 'q' => 'zander'], $this->ctx)->data['artikel'])->keyBy('id');
    $kurz = app(ArtikelListTool::class)->execute(['supplier_id' => $this->kluth->id], $this->ctx)->data['artikel'][0];

    expect($voll[$this->zander->id])->toMatchArray(['qty' => 1.0, 'unit_code' => 'kg', 'gp' => ['id' => $this->gp->id, 'name' => 'Zanderfilet: TK'], 'ist_lead' => true])
        ->and($voll[$this->zander->id])->toHaveKeys(['ek', 'aktion_bis', 'lieferstatus', 'is_discontinued'])
        ->and($voll[$this->kluthZander->id]['ist_lead'])->toBeFalse()
        ->and($kurz)->toBe(['id' => $this->kluthZander->id, 'designation' => 'Zander ganz', 'article_number' => $this->kluthZander->article_number,
            'supplier_id' => $this->kluth->id, 'supplier' => 'Kluth']);
});

it('artikel.SEARCH filtert nach supplier_id und art', function () {
    $treffer = fn (array $a) => collect(app(ArtikelSearchTool::class)->execute($a, $this->ctx)->data['artikel'])->pluck('id')->sort()->values()->all();

    expect($treffer(['q' => 'zander']))->toBe(($this->ids)($this->zander, $this->kluthZander))
        ->and($treffer(['q' => 'zander', 'supplier_id' => $this->kluth->id]))->toBe(($this->ids)($this->kluthZander))
        ->and($treffer(['q' => 'zander', 'only_active' => false, 'supplier_id' => $this->hanos->id]))->toBe(($this->ids)($this->zander, $this->alt))
        ->and($treffer(['q' => 'e', 'art' => 'nonfood']))->toBe(($this->ids)($this->becher));
});

it('unbekannte Parameter werden ignoriert, aber als warnungen gemeldet; ohne unbekannte kein warnungen-Schlüssel', function () {
    $mit = app(ArtikelListTool::class)->execute(['lieferant' => $this->kluth->id, 'per_page' => 5], $this->ctx);
    $ohne = app(ArtikelListTool::class)->execute(['supplier_id' => $this->kluth->id], $this->ctx);
    $such = app(SuppliersSearchTool::class)->execute(['q' => '', 'status' => 'aktiv'], $this->ctx);

    expect($mit->success)->toBeTrue()
        ->and($mit->data['warnungen'])->toHaveCount(1)
        ->and($mit->data['warnungen'][0])->toContain("unbekannter Parameter 'lieferant' ignoriert")->toContain('supplier_id')
        ->and($ohne->data)->not->toHaveKey('warnungen')
        ->and($such->data['warnungen'][0])->toContain("'status'");
});

it('suppliers.SEARCH ohne Query blättert mit Gesamtzahl und liefert Artikel-Zähler', function () {
    $seite = app(SuppliersSearchTool::class)->execute(['per_page' => 1, 'page' => 2], $this->ctx);

    expect($seite->success)->toBeTrue()
        ->and($seite->data)->toMatchArray(['total' => 3, 'page' => 2, 'last_page' => 3, 'per_page' => 1])
        ->and($seite->data['suppliers'][0])->toMatchArray(['name' => 'Kluth', 'item_count' => 1, 'mapped_count' => 1, 'via' => 'list']);
    $hanos = app(SuppliersSearchTool::class)->execute(['q' => 'Hanos'], $this->ctx)->data['suppliers'][0];
    expect($hanos)->toMatchArray(['item_count' => 4, 'mapped_count' => 1, 'group' => 'LM', 'via' => 'lexical']);
});

it('suppliers.SEARCH filtert nach Gruppe (Code, Id, "ohne") und „einstufen“; unbekannte Gruppe ist ein Fehler', function () {
    $namen = fn (array $a) => collect(app(SuppliersSearchTool::class)->execute($a, $this->ctx)->data['suppliers'])->pluck('name')->all();
    $gp = $this->gp;

    expect($namen(['gruppe' => 'verleih']))->toBe(['Zeltverleih'])
        ->and($namen(['gruppe' => (string) $this->hanos->group_id]))->toBe(['Hanos'])
        ->and($namen(['gruppe' => 'ohne']))->toBe(['Kluth'])
        ->and($namen(['q' => 'e', 'gruppe' => 'LM']))->toBe([])
        ->and($namen(['einstufen' => true]))->toBe(['Kluth'])
        ->and($namen(['q' => 'Kluth', 'einstufen' => true]))->toBe(['Kluth']);
    $gp->update(['rezepturware' => false]);
    expect($namen(['einstufen' => true]))->toBe([]);
    expect(app(SuppliersSearchTool::class)->execute(['gruppe' => 'QUATSCH'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR');
});
