<?php

use Platform\FoodAlchemist\Livewire\Settings\KonzeptTaxonomie;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptCategory;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabKlasse;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Livewire\Livewire;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Konzept-Taxonomie: zwei Bäume (Kategorie + Klasse) in den Einstellungen, gerendert über
 * die wiederverwendbare <x-foodalchemist::tree>. Prüft Service-Baum-Vertrag (ancestors/
 * has_children), Livewire-CRUD beider Bäume und D1-Isolation.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(ConceptService::class);
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
});

it('categoriesFlat/klassenFlat liefern Tiefe, Vorfahren-Kette und Kinder-Flag (Tree-Vertrag)', function () {
    $vor = $this->svc->createCategory($this->rootTeam, 'Buffets');
    $this->svc->createCategory($this->rootTeam, 'Lunchbuffet', $vor->id);

    $flat = collect($this->svc->categoriesFlat($this->rootTeam));
    $eltern = $flat->firstWhere('name', 'Buffets');
    $kind = $flat->firstWhere('name', 'Lunchbuffet');

    expect($eltern['depth'])->toBe(0)
        ->and($eltern['has_children'])->toBeTrue()
        ->and($eltern['ancestors'])->toBe([])
        ->and($kind['depth'])->toBe(1)
        ->and($kind['has_children'])->toBeFalse()
        ->and($kind['ancestors'])->toBe([$eltern['id']]);
});

it('rendert den Schirm mit beiden Bäumen', function () {
    Livewire::test(KonzeptTaxonomie::class)
        ->assertOk()
        ->assertSee('Kategorien')
        ->assertSee('Klassen');
});

it('Kategorie-Baum: anlegen, verschachteln (markierter Knoten = Eltern), umbenennen, löschen reparented', function () {
    // Settings-Umbau (#371): getrennte Top-/Sub-Anlage (neuTopKat/katNeuTop + neuSubKat/katNeuSub)
    $comp = Livewire::test(KonzeptTaxonomie::class)
        ->set('neuTopKat', 'Buffets')->call('katNeuTop');

    $eltern = FoodAlchemistConceptCategory::where('name', 'Buffets')->firstOrFail();

    // markierter Knoten wird Eltern für den nächsten Eintrag
    $comp->call('katWaehlen', $eltern->id)
        ->set('neuSubKat', 'Lunchbuffet')->call('katNeuSub');
    $kind = FoodAlchemistConceptCategory::where('name', 'Lunchbuffet')->firstOrFail();
    expect((int) $kind->parent_id)->toBe($eltern->id);

    // umbenennen
    $comp->call('katEditStart', $kind->id, 'Lunchbuffet')
        ->set('editKatName', 'Lunch-Buffet')->call('katRename');
    expect($kind->fresh()->name)->toBe('Lunch-Buffet');

    // löschen: Kind rückt zum Eltern (hier: Eltern löschen → Kind wird Wurzel)
    $comp->call('katLoeschen', $eltern->id);
    expect(FoodAlchemistConceptCategory::find($eltern->id))->toBeNull()
        ->and($kind->fresh()->parent_id)->toBeNull();
});

it('Klasse-Baum: anlegen + verschachteln als Vokabular-Baum', function () {
    $comp = Livewire::test(KonzeptTaxonomie::class)
        ->set('neuTopKlasse', 'Buffet')->call('klasseNeuTop');

    $eltern = FoodAlchemistVocabKlasse::where('name', 'Buffet')->firstOrFail();
    expect($eltern->slug)->toBe('buffet');

    $comp->call('klasseWaehlen', $eltern->id)
        ->set('neuSubKlasse', 'Flying Buffet')->call('klasseNeuSub');
    $kind = FoodAlchemistVocabKlasse::where('name', 'Flying Buffet')->firstOrFail();
    expect((int) $kind->parent_id)->toBe($eltern->id);

    // Klasse löschen → Kind rückt hoch; concepts.class-Strings bleiben unberührt
    $comp->call('klasseLoeschen', $eltern->id);
    expect(FoodAlchemistVocabKlasse::find($eltern->id))->toBeNull()
        ->and($kind->fresh()->parent_id)->toBeNull();
});

it('zählt Concepts je Kategorie und je Klasse-Name', function () {
    $kat = $this->svc->createCategory($this->rootTeam, 'Buffets');
    FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'C1', 'category_id' => $kat->id, 'class' => 'Buffet', 'is_template' => false]);
    FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'C2', 'category_id' => $kat->id, 'class' => 'Buffet', 'is_template' => false]);

    Livewire::test(KonzeptTaxonomie::class)
        ->assertViewHas('katCounts', fn ($c) => (int) ($c[$kat->id] ?? 0) === 2)
        ->assertViewHas('klasseCounts', fn ($c) => (int) ($c['Buffet'] ?? 0) === 2);
});

it('D1: Geschwister-Team sieht fremde Kategorie/Klasse nicht; Eltern-Katalog schon', function () {
    // Root legt Katalog an
    $rootKat = $this->svc->createCategory($this->rootTeam, 'Root-Linie');
    $this->svc->createKlasse($this->rootTeam, 'Root-Klasse');
    // Kind A legt eigene an
    $eigeneKat = $this->svc->createCategory($this->childA, 'Nur-A-Kategorie');
    $this->svc->createKlasse($this->childA, 'Nur-A-Klasse');

    $aKat = collect($this->svc->categoriesFlat($this->childA))->pluck('name');
    $bKat = collect($this->svc->categoriesFlat($this->childB))->pluck('name');
    $bKlasse = collect($this->svc->klassenFlat($this->childB))->pluck('name');

    expect($aKat)->toContain('Root-Linie')->toContain('Nur-A-Kategorie')   // eigene + geerbt
        ->and($bKat)->toContain('Root-Linie')->not->toContain('Nur-A-Kategorie') // Geschwister blind
        ->and($bKlasse)->toContain('Root-Klasse')->not->toContain('Nur-A-Klasse');
});

it('verweigert Pflege geerbter Knoten (D1-Guard)', function () {
    $rootKat = $this->svc->createCategory($this->rootTeam, 'Root-Linie');
    $rootKlasse = $this->svc->createKlasse($this->rootTeam, 'Root-Klasse');

    expect(fn () => $this->svc->renameCategory($this->childA, $rootKat->id, 'Gekapert'))
        ->toThrow(RuntimeException::class, 'Besitzer-Team');
    expect(fn () => $this->svc->renameKlasse($this->childA, $rootKlasse->id, 'Gekapert'))
        ->toThrow(RuntimeException::class, 'Besitzer-Team');
});
