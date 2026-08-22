<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Format-Modul (Phase A) — MCP-Lockstep: Reads visibleToTeam, Writes isOwnedBy +
 * draft-on-create. Spiegelt das Concepts-Tool-Muster.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->tool = fn (string $name, array $args = []) => $this->registry->get($name)->execute($args, $this->kontext);
});

it('formats.POST: legt Format als Entwurf an (draft-on-create)', function () {
    $res = ($this->tool)('foodalchemist.formats.POST', ['name' => 'CHEFS.CORNER', 'origin' => 'eigen']);
    expect($res->success)->toBeTrue()
        ->and($res->data['format']['status'])->toBe('draft')
        ->and($res->data['format']['origin'])->toBe('eigen');
});

it('formats.GET: liefert Editionen + Preis-Range', function () {
    $f = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'CHEFS.CORNER']);
    FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'FUTURE FLAVORS', 'format_id' => $f->id, 'format_position' => 0, 'price_per_person_cache' => 47.50]);
    FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'FARM TO TABLE', 'format_id' => $f->id, 'format_position' => 1, 'price_per_person_cache' => 49.50]);

    $res = ($this->tool)('foodalchemist.formats.GET', ['format_id' => $f->id]);
    expect($res->success)->toBeTrue()
        ->and($res->data['editions'])->toHaveCount(2)
        ->and($res->data['editions'][0]['name'])->toBe('FUTURE FLAVORS')
        ->and($res->data['format']['price_range'])->toBe(['min' => 47.50, 'max' => 49.50]);
});

it('format_editions.POST: ordnet ein Konzept als Edition zu', function () {
    $f = FoodAlchemistFormat::create(['team_id' => $this->rootTeam->id, 'name' => 'CHEFS.CORNER']);
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'URBAN & FLAVOUR']);

    $res = ($this->tool)('foodalchemist.format_editions.POST', ['format_id' => $f->id, 'concept_id' => $c->id]);
    expect($res->success)->toBeTrue()
        ->and((int) $res->data['edition']['format_id'])->toBe($f->id);
    expect((int) FoodAlchemistConcept::find($c->id)->format_id)->toBe($f->id);
});

it('Tenancy: PUT auf ein fremdes (Kind-)Format ist NOT_FOUND aus Root-Kontext', function () {
    $fremd = FoodAlchemistFormat::create(['team_id' => $this->childA->id, 'name' => 'Kind-Format']);
    $res = ($this->tool)('foodalchemist.formats.PUT', ['format_id' => $fremd->id, 'claim' => 'hack']);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND');
});
