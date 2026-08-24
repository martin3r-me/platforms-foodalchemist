<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Formate\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Format-Modul (Phase D, Concepter 2.0) — Format-Editor als Oberkapitel→Unterkapitel-Baum:
 * Editionen inline anlegen (mit Auto-Sektions-Gerüst), Wording pro Edition (Titel/Claim/
 * Hinführung, Foodbook-Kapitel-Parität).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->fsvc = app(FormatService::class);
    $this->fbsvc = app(FoodbookService::class);
});

it('createEdition legt ein Concept an, hängt es als Edition an und seedet das Sektions-Gerüst', function () {
    $f = $this->fsvc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);

    $ed = $this->fsvc->createEdition($this->rootTeam, $f->id, 'FUTURE FLAVORS', true);

    expect((int) $ed->format_id)->toBe($f->id)
        ->and($ed->name)->toBe('FUTURE FLAVORS')
        ->and($ed->status)->toBe('draft')
        ->and($ed->slots()->where('type', 'header')->count())->toBe(count(FormatService::SEKTIONS_GERUEST));
});

it('updateEditionWording pflegt Titel/Claim/Hinführung (claim neu speicherbar)', function () {
    $f = $this->fsvc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);
    $ed = $this->fsvc->createEdition($this->rootTeam, $f->id, 'FUTURE FLAVORS', false);

    $ed = $this->fsvc->updateEditionWording($this->rootTeam, $f->id, $ed->id, [
        'consumer_name' => 'Future Flavors', 'claim' => 'Die neue Küche der Welt', 'description' => 'Molecular, live angerichtet.',
    ]);

    expect($ed->consumer_name)->toBe('Future Flavors')
        ->and($ed->claim)->toBe('Die neue Küche der Welt')
        ->and($ed->description)->toBe('Molecular, live angerichtet.');
});

it('updateEditionWording lehnt ein Concept ab, das nicht zu DIESEM Format gehört', function () {
    $f = $this->fsvc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);
    $fremd = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Standalone']); // kein format_id

    expect(fn () => $this->fsvc->updateEditionWording($this->rootTeam, $f->id, $fremd->id, ['claim' => 'x']))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('Editor: neue Edition inline anlegen + Wording pro Unterkapitel speichern', function () {
    $this->actingAs($this->makeUser($this->rootTeam));
    $f = $this->fsvc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);

    $t = Livewire::test(Editor::class)->call('oeffnen', $f->id)->call('setTab', 'editionen')
        ->set('neueEditionName', 'FARM TO TABLE')->call('neueEdition')
        ->assertDispatched('formate-gespeichert');

    $ed = FoodAlchemistConcept::where('format_id', $f->id)->firstOrFail();
    expect($ed->name)->toBe('FARM TO TABLE')
        ->and($ed->slots()->where('type', 'header')->count())->toBe(count(FormatService::SEKTIONS_GERUEST));

    $t->call('editionWordingSpeichern', $ed->id, 'claim', 'Natur pur auf dem Teller')
        ->assertDispatched('formate-gespeichert');
    expect(FoodAlchemistConcept::find($ed->id)->claim)->toBe('Natur pur auf dem Teller');
});
