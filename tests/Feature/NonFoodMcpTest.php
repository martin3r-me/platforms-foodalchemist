<?php

use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItemNonfood;
use Platform\FoodAlchemist\Services\NonFoodService;
use Platform\FoodAlchemist\Support\FaBereiche;
use Platform\FoodAlchemist\Support\NonFoodRegeln;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\NonfoodPruefenTool;
use Platform\FoodAlchemist\Tools\NonfoodSetTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** MCP-Lockstep zu Non-Food PR 1: nonfood.PRUEFLISTE (lesen) und nonfood.SET (Urteil von Hand). */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Einkauf');
    $this->actingAs($this->user);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
    $this->sup = FoodAlchemistSupplier::create(['team_id' => $this->rootTeam->id, 'name' => 'Hanos']);
    $this->la = fn (string $d, ?int $teamId = null) => FoodAlchemistSupplierItem::create(['team_id' => $teamId ?? $this->rootTeam->id, 'supplier_id' => $this->sup->id, 'designation' => $d, 'qty' => 1, 'unit_code' => 'kg']);
});

it('Prüfliste: Standard zeigt die unklaren Artikel mit Urteil und Begründung, art nonfood/food filtern, Paging und Lieferant', function () {
    ($this->la)('SERVIETTE 2-LAGIG');
    ($this->la)('Flammkuchen-Karton 7 kg');
    ($this->la)('Butter 250 g');
    app(NonFoodService::class)->regelLauf(null, true);
    $tool = new NonfoodPruefenTool;

    $p = $tool->execute([], $this->ctx);
    expect($p->success)->toBeTrue()->and($p->data['art'])->toBe('pruefen')->and($p->data['total'])->toBe(1)
        ->and($p->data['artikel'][0])->toMatchArray(['designation' => 'Flammkuchen-Karton 7 kg', 'ist_nonfood' => null, 'quelle' => 'regel', 'pruefung_offen' => true, 'lieferant' => 'Hanos']);

    $n = $tool->execute(['art' => 'nonfood', 'supplier_id' => $this->sup->id], $this->ctx);
    expect($n->data['total'])->toBe(1)->and($n->data['artikel'][0])->toMatchArray(['designation' => 'SERVIETTE 2-LAGIG', 'ist_nonfood' => true, 'subkategorie' => NonFoodRegeln::SUB_DEKO]);

    $f = $tool->execute(['art' => 'food', 'per_page' => 1], $this->ctx);
    expect($f->data['total'])->toBe(2)->and($f->data['last_page'])->toBe(2)->and($f->data['artikel'])->toHaveCount(1);

    expect($tool->execute(['art' => 'quatsch'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR');
});

it('SET: Non-Food mit Unterkategorie, Food bestätigen, Urteil zurücknehmen; Fehlerfälle und geerbter Artikel', function () {
    $la = ($this->la)('Lampion Papier 35 cm');
    $tool = new NonfoodSetTool;

    $r = $tool->execute(['id' => $la->id, 'ist_nonfood' => true, 'unterkategorie' => NonFoodRegeln::SUB_DEKO], $this->ctx);
    expect($r->success)->toBeTrue()->and($r->data)->toMatchArray(['ist_nonfood' => true, 'quelle' => 'manuell', 'unterkategorie' => NonFoodRegeln::SUB_DEKO]);
    expect(FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $la->id)->value('quelle'))->toBe('manuell');

    // ein Lauf überschreibt das Urteil von Hand nicht
    app(NonFoodService::class)->regelLauf(null, true);
    expect(FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $la->id)->value('quelle'))->toBe('manuell');

    expect($tool->execute(['id' => $la->id, 'ist_nonfood' => false], $this->ctx)->data['ist_nonfood'])->toBeFalse();
    $zurueck = $tool->execute(['id' => $la->id, 'zuruecknehmen' => true], $this->ctx);
    expect($zurueck->data['zurueckgenommen'])->toBeTrue()->and(FoodAlchemistSupplierItemNonfood::where('supplier_item_id', $la->id)->exists())->toBeFalse();

    // früheres Verhalten bleibt: ein ausdrückliches ist_nonfood=null nimmt ebenfalls zurück
    $tool->execute(['id' => $la->id, 'ist_nonfood' => true], $this->ctx);
    expect($tool->execute(['id' => $la->id, 'ist_nonfood' => null], $this->ctx)->data['zurueckgenommen'])->toBeTrue();

    expect($tool->execute(['id' => $la->id], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR')
        ->and($tool->execute(['id' => $la->id, 'ist_nonfood' => 'ja'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR')
        ->and($tool->execute(['id' => $la->id, 'ist_nonfood' => true, 'unterkategorie' => 'Erfunden'], $this->ctx)->errorCode)->toBe('VALIDATION_ERROR')
        ->and($tool->execute(['id' => 999999, 'ist_nonfood' => true], $this->ctx)->errorCode)->toBe('NOT_FOUND');

    // Gegenprobe: ein Artikel des Eltern-Teams ist sichtbar, gehört dem Kind-Team aber nicht
    $geerbt = ($this->la)('Kochjacke Weiß', $this->rootTeam->id);
    $kind = new ToolContext($this->makeUser($this->childA, 'Kind'), $this->childA);
    expect($tool->execute(['id' => $geerbt->id, 'ist_nonfood' => true], $kind)->errorCode)->toBe('ACCESS_DENIED');
});

it('Schema: kein Union-Typ (der Core-ToolExecutor stürzt daran ab), ist_nonfood ist boolean, Zurücknehmen ein eigener Schalter', function () {
    $schema = (new NonfoodSetTool)->getSchema();
    $typen = array_map(fn ($p) => $p['type'] ?? null, $schema['properties']);

    expect($typen['ist_nonfood'])->toBe('boolean')->and($typen['zuruecknehmen'])->toBe('boolean')->and($schema['required'])->toBe(['id']);
    foreach ((new NonfoodPruefenTool)->getSchema()['properties'] + $schema['properties'] as $name => $p) {
        expect(is_array($p['type'] ?? null))->toBeFalse("{$name}: Array-Typ im Schema");
    }
});

it('Tools gehören zum Bereich Stammdaten und sind registriert', function () {
    expect(FaBereiche::fuerTool('foodalchemist.nonfood.PRUEFLISTE'))->toBe('stammdaten')->and(FaBereiche::fuerTool('foodalchemist.nonfood.SET'))->toBe('stammdaten');
});
