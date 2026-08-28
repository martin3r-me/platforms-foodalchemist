<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Concepter\Editor;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 43 (Bild-Epic) — Concept-Titelbild: Service-Upload/-Löschen (team-gescopt) + Conceptor-Wiring.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->concepts = app(ConceptService::class);
    $this->actingAs($this->makeUser($this->rootTeam, 'Conceptor'));
    Storage::fake('public');
});

it('ConceptService::storeImage/clearImage setzt + löscht das Titelbild', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Menü', ['kind' => 'concept']);

    $this->concepts->storeImage($this->rootTeam, $concept->id, UploadedFile::fake()->image('c.jpg', 400, 200));
    expect($concept->refresh()->image_path)->not->toBeNull();

    $this->concepts->clearImage($this->rootTeam, $concept->id);
    expect($concept->refresh()->image_path)->toBeNull();
});

it('storeImage auf fremdes (geerbtes, nicht eigenes) Concept wirft (isOwnedBy)', function () {
    // Root-Concept ist für childA sichtbar (Ancestry), aber nicht besessen.
    $concept = $this->makeConcept($this->rootTeam, 'Menü', ['kind' => 'concept']);
    expect(fn () => $this->concepts->storeImage($this->childA, $concept->id, UploadedFile::fake()->image('c.jpg')))
        ->toThrow(RuntimeException::class);
});

it('Conceptor-Editor: Upload verdrahtet storeImage', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Menü', ['kind' => 'concept']);

    Livewire::test(Editor::class)
        ->set('id', $concept->id)
        ->set('conceptImageUpload', UploadedFile::fake()->image('titel.jpg', 400, 200));

    expect($concept->refresh()->image_path)->not->toBeNull();
});

it('ConceptService::addGalleryImage/removeGalleryImage pflegt die Galerie (team-gescopt)', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Menü', ['kind' => 'concept']);

    $a = $this->concepts->addGalleryImage($this->rootTeam, $concept->id, UploadedFile::fake()->image('g1.jpg', 400, 300));
    $b = $this->concepts->addGalleryImage($this->rootTeam, $concept->id, UploadedFile::fake()->image('g2.jpg', 400, 300));
    expect($concept->images()->count())->toBe(2);
    expect($b->sort_order)->toBeGreaterThan($a->sort_order);

    $this->concepts->removeGalleryImage($this->rootTeam, $a->id);
    expect($concept->images()->count())->toBe(1);
});

it('addGalleryImage auf fremdes Concept wirft (isOwnedBy)', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Menü', ['kind' => 'concept']);
    expect(fn () => $this->concepts->addGalleryImage($this->childA, $concept->id, UploadedFile::fake()->image('g.jpg')))
        ->toThrow(RuntimeException::class);
});

it('Conceptor-Editor: Galerie-Upload (Mehrfach) verdrahtet addGalleryImage', function () {
    $concept = $this->makeConcept($this->rootTeam, 'Menü', ['kind' => 'concept']);

    Livewire::test(Editor::class)
        ->set('id', $concept->id)
        ->set('conceptGalleryUpload', [
            UploadedFile::fake()->image('extra1.jpg', 400, 300),
            UploadedFile::fake()->image('extra2.jpg', 400, 300),
        ]);

    expect($concept->images()->count())->toBe(2);
});

it('FoodbookService::setKapitelImage/clearKapitelImage setzt + löscht das Kapitel-Bild', function () {
    $fb = $this->makeFoodbook($this->rootTeam, 'Katalog', ['personen' => 4]);
    $kap = $this->makeChapter($fb, ['title' => 'Vorspeisen', 'position' => 1]);
    $svc = app(\Platform\FoodAlchemist\Services\FoodbookService::class);

    $svc->setKapitelImage($this->rootTeam, $kap->id, UploadedFile::fake()->image('k.jpg', 400, 200));
    expect($kap->refresh()->image_path)->not->toBeNull();

    $svc->clearKapitelImage($this->rootTeam, $kap->id);
    expect($kap->refresh()->image_path)->toBeNull();
});
