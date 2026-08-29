<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D4b: Lieferantenartikel-CRUD (artikel.POST/DELETE/DISCONTINUE, _allergene/_deklarationen/
 * _naehrwerte.PUT, _preise.POST/DELETE). Owner-Guard, Delete-Confirm.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    $this->supplierId = $this->registry->get('foodalchemist.suppliers.POST')->execute(['input' => ['name' => 'Hanos']], $this->kontext)->data['id'];
    $this->neuArtikel = fn (string $d = 'Zanderfilet') => $this->registry->get('foodalchemist.artikel.POST')
        ->execute(['supplier_id' => $this->supplierId, 'input' => ['designation' => $d]], $this->kontext);
});

it('Registry-Smoke: artikel.* + preise + allergene/deklarationen/naehrwerte registriert', function () {
    foreach ([
        'foodalchemist.artikel.POST', 'foodalchemist.artikel.DELETE', 'foodalchemist.artikel.DISCONTINUE',
        'foodalchemist.artikel_allergene.PUT', 'foodalchemist.artikel_deklarationen.PUT', 'foodalchemist.artikel_naehrwerte.PUT',
        'foodalchemist.artikel_preise.POST', 'foodalchemist.artikel_preise.DELETE',
    ] as $name) {
        expect($this->registry->get($name))->not->toBeNull($name);
        expect($this->registry->get($name)->getSchema()['type'] ?? null)->toBe('object', $name);
    }
});

it('POST + DISCONTINUE + allergene/naehrwerte + Preis-POST/DELETE + DELETE', function () {
    $post = ($this->neuArtikel)();
    expect($post->success)->toBeTrue('post: ' . ($post->error ?? ''));
    $id = $post->data['id'];

    expect(($this->run)('foodalchemist.artikel.DISCONTINUE', ['id' => $id])->data['discontinued'])->toBeTrue();
    expect(($this->run)('foodalchemist.artikel_allergene.PUT', ['id' => $id, 'werte' => []])->success)->toBeTrue();
    expect(($this->run)('foodalchemist.artikel_naehrwerte.PUT', ['id' => $id, 'werte' => []])->success)->toBeTrue();

    $preis = ($this->run)('foodalchemist.artikel_preise.POST', ['id' => $id, 'preis' => 8.9]);
    expect($preis->success)->toBeTrue('preis: ' . ($preis->error ?? ''));
    expect(($this->run)('foodalchemist.artikel_preise.DELETE', ['id' => $id, 'price_id' => $preis->data['price_id']])->data['deleted'])->toBeTrue();

    expect(($this->run)('foodalchemist.artikel.DELETE', ['id' => $id])->errorCode)->toBe('CONFIRM_REQUIRED');
    expect(($this->run)('foodalchemist.artikel.DELETE', ['id' => $id, 'confirm' => true])->data['deleted'])->toBeTrue();
});

it('POST ohne designation → VALIDATION_ERROR; preis < 0 → VALIDATION_ERROR', function () {
    expect(($this->run)('foodalchemist.artikel.POST', ['supplier_id' => $this->supplierId, 'input' => []])->errorCode)->toBe('VALIDATION_ERROR');
    $id = ($this->neuArtikel)()->data['id'];
    expect(($this->run)('foodalchemist.artikel_preise.POST', ['id' => $id, 'preis' => -1])->errorCode)->toBe('VALIDATION_ERROR');
});

it('Guards: unbekannter Artikel → NOT_FOUND; fremd → ACCESS_DENIED', function () {
    $id = ($this->neuArtikel)('Kabeljau')->data['id'];
    expect(($this->run)('foodalchemist.artikel.DISCONTINUE', ['id' => 999999])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.artikel.DISCONTINUE', ['id' => $id], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
});
