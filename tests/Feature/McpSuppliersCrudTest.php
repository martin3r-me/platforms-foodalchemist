<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MCP-Steuerbarkeit · D4a: Lieferanten-CRUD (suppliers.POST/PUT/STATUS/DEACTIVATE, supplier_conditions.PUT,
 * supplier_contacts.POST, supplier_documents.POST). Owner-Guard, Dedup.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->childKontext = new ToolContext($this->makeUser($this->childA), $this->childA);
    $this->run = fn (string $n, array $a, ?ToolContext $k = null) => $this->registry->get($n)->execute($a, $k ?? $this->kontext);
    $this->neuSupplier = fn (string $name = 'Hanos') => $this->registry->get('foodalchemist.suppliers.POST')->execute(['input' => ['name' => $name]], $this->kontext);
});

it('Registry-Smoke: suppliers.* + supplier_conditions/contacts/documents registriert', function () {
    foreach ([
        'foodalchemist.suppliers.POST', 'foodalchemist.suppliers.PUT', 'foodalchemist.suppliers.STATUS',
        'foodalchemist.suppliers.DEACTIVATE', 'foodalchemist.supplier_conditions.PUT',
        'foodalchemist.supplier_contacts.POST', 'foodalchemist.supplier_documents.POST',
    ] as $name) {
        expect($this->registry->get($name))->not->toBeNull($name);
        expect($this->registry->get($name)->getSchema()['type'] ?? null)->toBe('object', $name);
    }
});

it('POST legt an (Dedup); PUT bearbeitet; DEACTIVATE; contacts.POST', function () {
    $post = ($this->neuSupplier)('Chefs Culinar');
    expect($post->success)->toBeTrue();
    $id = $post->data['id'];

    expect(($this->neuSupplier)('Chefs Culinar')->errorCode)->toBe('VALIDATION_ERROR');   // Dedup

    expect(($this->run)('foodalchemist.suppliers.PUT', ['id' => $id, 'input' => ['name' => 'Chefs Culinar GmbH']])->success)->toBeTrue();
    expect(FoodAlchemistSupplier::find($id)->name)->toBe('Chefs Culinar GmbH');

    expect(($this->run)('foodalchemist.suppliers.DEACTIVATE', ['id' => $id])->data['inactive'])->toBeTrue();

    $contact = ($this->run)('foodalchemist.supplier_contacts.POST', ['supplier_id' => $id, 'input' => ['name' => 'Frau Meyer']]);
    expect($contact->success)->toBeTrue('contact: ' . ($contact->error ?? ''));
});

it('POST ohne name → VALIDATION_ERROR', function () {
    expect(($this->run)('foodalchemist.suppliers.POST', ['input' => []])->errorCode)->toBe('VALIDATION_ERROR');
});

it('Guards: unbekannt → NOT_FOUND; fremd → ACCESS_DENIED', function () {
    $id = ($this->neuSupplier)('Kluth')->data['id'];
    expect(($this->run)('foodalchemist.suppliers.PUT', ['id' => 999999, 'input' => ['name' => 'X']])->errorCode)->toBe('NOT_FOUND');
    expect(($this->run)('foodalchemist.suppliers.PUT', ['id' => $id, 'input' => ['name' => 'X']], $this->childKontext)->errorCode)->toBe('ACCESS_DENIED');
});
