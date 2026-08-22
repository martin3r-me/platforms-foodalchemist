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

it('attachEdition/detachEdition: setzt+löst format_id, ohne Recompute', function () {
    $f = $this->svc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'FUTURE FLAVORS', 'price_per_person_cache' => 47.50]);

    $c = $this->svc->attachEdition($this->rootTeam, $f->id, $c->id);
    expect((int) $c->format_id)->toBe($f->id)->and((int) $c->format_position)->toBe(0)
        ->and((float) $c->price_per_person_cache)->toBe(47.50); // unangetastet

    $c = $this->svc->detachEdition($this->rootTeam, $c->id);
    expect($c->format_id)->toBeNull();
});

it('attachEdition: fremd-besessenes Concept wirft (beidseitiger Guard)', function () {
    $f = $this->svc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);
    $fremd = FoodAlchemistConcept::create(['team_id' => $this->childA->id, 'name' => 'Kind-Concept']);
    // Root sieht das Kind-Concept NICHT einmal → ModelNotFound.
    expect(fn () => $this->svc->attachEdition($this->rootTeam, $f->id, $fremd->id))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('reorderEditions: setzt format_position in gegebener Reihenfolge', function () {
    $f = $this->svc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);
    $a = $this->svc->attachEdition($this->rootTeam, $f->id, FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'A'])->id);
    $b = $this->svc->attachEdition($this->rootTeam, $f->id, FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'B'])->id);

    $this->svc->reorderEditions($this->rootTeam, $f->id, [$b->id, $a->id]);
    expect($f->load('editions')->editions->pluck('name')->all())->toBe(['B', 'A']);
});

it('delete: Editionen werden wieder freistehend (nullOnDelete)', function () {
    $f = $this->svc->create($this->rootTeam, ['name' => 'CHEFS.CORNER']);
    $c = $this->svc->attachEdition($this->rootTeam, $f->id, FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'A'])->id);

    $this->svc->delete($this->rootTeam, $f->id);
    expect(FoodAlchemistConcept::find($c->id)->format_id)->toBeNull();
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
