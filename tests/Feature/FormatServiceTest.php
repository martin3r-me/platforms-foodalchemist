<?php

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistFormatImage;
use Platform\FoodAlchemist\Services\FormatService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Format-Modul (Phase A) — FormatService: CRUD, Editionen-Zuordnung (Ownership-Guard
 * beidseitig), Bild-Verwaltung (Hero/Reorder/Clear), Preis-Range read-only. Kein
 * Recompute beim Gruppieren.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(FormatService::class);
});

it('create: legt Format als Entwurf an', function () {
    $f = $this->svc->create($this->rootTeam, ['name' => 'CHEFS.CORNER', 'origin' => 'eigen']);
    expect($f->status)->toBe('draft')->and($f->name)->toBe('CHEFS.CORNER')->and($f->origin)->toBe('eigen');
});

it('update: pflegt Identität; unbekannte Herkunft wirft', function () {
    $f = $this->svc->create($this->rootTeam, ['name' => 'X']);
    $f = $this->svc->update($this->rootTeam, $f->id, ['claim' => 'WORLD ON A PLATE', 'origin' => 'KUNDE', 'story' => '  ']);
    expect($f->claim)->toBe('WORLD ON A PLATE')
        ->and($f->origin)->toBe('kunde')   // normalisiert
        ->and($f->story)->toBeNull();       // leer → null

    expect(fn () => $this->svc->update($this->rootTeam, $f->id, ['origin' => 'quatsch']))
        ->toThrow(RuntimeException::class);
});

it('update: geerbtes Format ist read-only (guardOwner)', function () {
    $f = $this->svc->create($this->rootTeam, ['name' => 'Root-Format']);
    // Kind A sieht das Root-Format (Kette), darf es aber nicht pflegen.
    expect(fn () => $this->svc->update($this->childA, $f->id, ['claim' => 'hack']))
        ->toThrow(RuntimeException::class);
});

it('delete: referenziertes Concept bleibt unangetastet (F2e — reine Slot-Referenz, kein Besitz)', function () {
    $f = $this->svc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'FUTURE FLAVORS', 'price_per_person_cache' => 47.50]);
    $this->svc->slotConceptEinfuegen($this->rootTeam, $f->id, $c->id);

    $this->svc->delete($this->rootTeam, $f->id);

    // Format ist weg (soft-delete), das referenzierte Concept lebt weiter (kein Datenverlust).
    expect(FoodAlchemistFormat::find($f->id))->toBeNull()
        ->and(FoodAlchemistConcept::find($c->id))->not->toBeNull()
        ->and((float) FoodAlchemistConcept::find($c->id)->price_per_person_cache)->toBe(47.50);
});

it('Bild-Verwaltung: setHero hält genau einen Hero, clearImage rückt Hero nach', function () {
    $f = $this->svc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);
    // Direkt angelegte Bild-Zeilen (ohne echten Upload) — Verwaltungs-Logik testen.
    $i1 = FoodAlchemistFormatImage::create(['team_id' => $this->rootTeam->id, 'format_id' => $f->id, 'is_hero' => true, 'sort_order' => 10]);
    $i2 = FoodAlchemistFormatImage::create(['team_id' => $this->rootTeam->id, 'format_id' => $f->id, 'is_hero' => false, 'sort_order' => 20]);

    $this->svc->setHero($this->rootTeam, $i2->id);
    expect($i1->refresh()->is_hero)->toBeFalse()->and($i2->refresh()->is_hero)->toBeTrue();

    // Hero löschen → nächstes Bild wird Hero (immer 0/1 Hero).
    $this->svc->clearImage($this->rootTeam, $i2->id);
    expect(FoodAlchemistFormatImage::find($i2->id))->toBeNull()
        ->and($i1->refresh()->is_hero)->toBeTrue();
});
