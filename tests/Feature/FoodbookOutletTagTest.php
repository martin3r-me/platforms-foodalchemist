<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\ConcepterDimensionen;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 19 E3.6 (M5-Hälfte, abkoppelbar): Outlet-Vokabular als optionaler Kapitel-Tag.
 * Outlet ist bewusst KEINE Planungs-Ebene und NICHT in leitplanken() — daher hier nur
 * Schema/Model/Settings-CRUD (Tag setzen, Relation, Lösch-Schutz bei Nutzung).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->fb = app(FoodbookService::class);
});

it('taggt ein Kapitel mit einem Outlet — Relation in beide Richtungen', function () {
    $outlet = FoodAlchemistOutlet::create([
        'team_id' => $this->rootTeam->id, 'name' => 'Bankett', 'color' => '#8b5cf6',
    ]);

    $foodbook = $this->fb->create($this->rootTeam, ['label' => 'Adler']);
    $kapitel = $this->fb->addKapitel($this->rootTeam, $foodbook->id, ['title' => 'Gala-Abend']);
    $kapitel->update(['outlet_id' => $outlet->id]);

    expect($kapitel->refresh()->outlet->name)->toBe('Bankett')
        ->and($kapitel->outlet->color)->toBe('#8b5cf6')
        ->and($outlet->refresh()->chapters->pluck('id')->all())->toBe([$kapitel->id]);
});

it('Settings-CRUD: Outlet anlegen und genutzten Outlet nicht hart löschen (Referenz-Guard)', function () {
    $comp = Livewire::test(ConcepterDimensionen::class)
        ->set('neu.outlets', 'Restaurant')
        ->call('create', 'outlets');

    $outlet = FoodAlchemistOutlet::where('team_id', $this->rootTeam->id)->where('name', 'Restaurant')->first();
    expect($outlet)->not->toBeNull();

    // Ungenutzt → löschbar wäre ok; jetzt an ein Kapitel hängen und Lösch-Schutz prüfen.
    $foodbook = $this->fb->create($this->rootTeam, ['label' => 'Adler']);
    $kapitel = $this->fb->addKapitel($this->rootTeam, $foodbook->id, ['title' => 'Menü']);
    $kapitel->update(['outlet_id' => $outlet->id]);

    $comp->call('delete', 'outlets', $outlet->id);
    expect(FoodAlchemistOutlet::find($outlet->id))->not->toBeNull()
        ->and($comp->get('fehler'))->toContain('genutzt');
});
