<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Cleanup-Zwei-Schritt via MCP: Konzept-Block aus Foodbook entfernen
 * (foodbook_blocks.DELETE) → dann Konzept löschen (concepts.DELETE).
 * Referenz-Schutz: solange referenziert, blockt concepts.DELETE typisiert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
});

it('Registry-Smoke: DELETE-Tools registriert (Naming <modul>.resource.DELETE)', function () {
    foreach (['foodalchemist.foodbook_blocks.DELETE', 'foodalchemist.concepts.DELETE'] as $name) {
        expect($this->registry->get($name))->not->toBeNull($name);
        expect($this->registry->get($name)->getSchema()['type'] ?? null)->toBe('object', $name);
        expect($this->registry->get($name)->getMetadata()['read_only'])->toBeFalse($name);
    }
});

it('Cleanup-Zwei-Schritt: referenziertes Konzept blockt, nach Block-Entfernen löschbar', function () {
    $concepts = app(ConceptService::class);
    $foodbooks = app(FoodbookService::class);

    $concept = $concepts->create($this->rootTeam, ['name' => 'Grill-Buffet']);
    $concepts->addSlot($this->rootTeam, $concept->id, ['role' => 'Hauptgang']);

    $fb = $foodbooks->create($this->rootTeam, ['label' => 'Angebot Adler', 'customer' => 'Hotel Adler', 'personen' => 100]);
    $kap = $foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Menü']);
    $block = $foodbooks->addBlock($this->rootTeam, $kap->id, ['type' => 'concept_ref', 'concept_id' => $concept->id]);

    // Schritt 2 zu früh: Konzept noch referenziert → blockt mit Foodbook-Liste.
    $blockiert = $this->registry->get('foodalchemist.concepts.DELETE')
        ->execute(['concept_id' => $concept->id], $this->kontext);
    expect($blockiert->success)->toBeFalse()
        ->and($blockiert->errorCode)->toBe('HAS_REFERENCES')
        ->and($blockiert->metadata['blocking_foodbooks'][0]['id'])->toBe($fb->id);

    // Schritt 1: Block aus Foodbook entfernen → meldet Konzept jetzt frei.
    $entfernt = $this->registry->get('foodalchemist.foodbook_blocks.DELETE')
        ->execute(['block_id' => $block->id], $this->kontext);
    expect($entfernt->success)->toBeTrue()
        ->and($entfernt->data['removed_block']['concept_id'])->toBe($concept->id)
        ->and($entfernt->data['concept_now_deletable'])->toBeTrue()
        ->and($entfernt->data['concept_still_in_foodbooks'])->toBe([]);

    // Schritt 2 jetzt: Konzept löschen → erfolgreich, soft-deleted.
    $geloescht = $this->registry->get('foodalchemist.concepts.DELETE')
        ->execute(['concept_id' => $concept->id], $this->kontext);
    expect($geloescht->success)->toBeTrue()
        ->and($geloescht->data['deleted_concept']['id'])->toBe($concept->id);

    expect(\Platform\FoodAlchemist\Models\FoodAlchemistConcept::whereKey($concept->id)->exists())->toBeFalse();
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistConcept::withTrashed()->whereKey($concept->id)->exists())->toBeTrue();
});

it('DELETE-Tools: unsichtbare/fehlende IDs → NOT_FOUND', function () {
    $blockMiss = $this->registry->get('foodalchemist.foodbook_blocks.DELETE')
        ->execute(['block_id' => 999999], $this->kontext);
    expect($blockMiss->success)->toBeFalse()->and($blockMiss->errorCode)->toBe('NOT_FOUND');

    $conceptMiss = $this->registry->get('foodalchemist.concepts.DELETE')
        ->execute(['concept_id' => 999999], $this->kontext);
    expect($conceptMiss->success)->toBeFalse()->and($conceptMiss->errorCode)->toBe('NOT_FOUND');
});
