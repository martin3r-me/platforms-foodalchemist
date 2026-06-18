<?php

use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Platform\FoodAlchemist\Services\VocabularyService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M1-02: Einheiten-CRUD — D1-Ownership, Slug-Kollision (V-06),
 * Inaktiv-Lebenszyklus (AT-D1-04), Delete-Guard bei GP-Referenz.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->vocab = app(VocabularyService::class);

    $this->stk = $this->vocab->createEinheit($this->rootTeam, [
        'slug' => 'stk', 'display_de' => 'Stück', 'dimension' => 'count', 'default_in_g' => '85,5',
    ]);
});

it('legt an, normalisiert Dezimal-Komma und listet je Team-Kette', function () {
    expect((float) $this->stk->default_in_g)->toBe(85.5)
        ->and($this->vocab->listEinheiten($this->childA)->pluck('slug'))->toContain('stk') // geerbt sichtbar
        ->and($this->vocab->listEinheiten($this->childB)->pluck('slug'))->toContain('stk');
});

it('default_in_g/ml: Tippfehler/negativ wird null statt stiller 0 (kein vergifteter Gramm-Faktor)', function () {
    $bad = $this->vocab->createEinheit($this->rootTeam, [
        'slug' => 'kiste', 'display_de' => 'Kiste', 'dimension' => 'count', 'default_in_g' => 'abc',
    ]);
    expect($bad->default_in_g)->toBeNull();

    $neg = $this->vocab->createEinheit($this->rootTeam, [
        'slug' => 'palette', 'display_de' => 'Palette', 'dimension' => 'count', 'default_in_g' => '-5',
    ]);
    expect($neg->default_in_g)->toBeNull();

    // gültige Werte (inkl. Komma) bleiben erhalten
    $ok = $this->vocab->createEinheit($this->rootTeam, [
        'slug' => 'becher', 'display_de' => 'Becher', 'dimension' => 'volume', 'default_in_ml' => '250,5',
    ]);
    expect((float) $ok->default_in_ml)->toBe(250.5);
});

it('verweigert Slug-Kollision in der Team-Kette (V-06)', function () {
    expect(fn () => $this->vocab->createEinheit($this->rootTeam, ['slug' => 'stk', 'display_de' => 'Doppelt']))
        ->toThrow(RuntimeException::class, 'existiert bereits');
});

it('verweigert Edit geerbter Einheiten (D1: Pflege nur Besitzer-Team)', function () {
    expect(fn () => $this->vocab->updateEinheit($this->childA, $this->stk->id, ['display_de' => 'Gekapert']))
        ->toThrow(RuntimeException::class, 'Besitzer-Team');

    // eigenes Team darf
    $this->vocab->updateEinheit($this->rootTeam, $this->stk->id, ['display_de' => 'Stück (groß)', 'sort_order' => 3]);
    expect($this->stk->fresh()->display_de)->toBe('Stück (groß)');
});

it('inaktiv blendet aus der Standard-Liste aus, bleibt aber erhalten (AT-D1-04)', function () {
    $this->vocab->setEinheitInactive($this->rootTeam, $this->stk->id, true);

    expect($this->vocab->listEinheiten($this->rootTeam)->pluck('id'))->not->toContain($this->stk->id)
        ->and($this->vocab->listEinheiten($this->rootTeam, includeInactive: true)->pluck('id'))->toContain($this->stk->id)
        ->and(FoodAlchemistVocabEinheit::find($this->stk->id))->not->toBeNull();
});

it('blockt Delete bei GP-Referenz, erlaubt ihn ohne (V-06)', function () {
    $gp = $this->makeGp($this->rootTeam, 'Eier');
    $gp->update(['preferred_count_unit_id' => $this->stk->id]);

    expect(fn () => $this->vocab->deleteEinheit($this->rootTeam, $this->stk->id))
        ->toThrow(RuntimeException::class, 'referenziert');

    $gp->update(['preferred_count_unit_id' => null]);
    $this->vocab->deleteEinheit($this->rootTeam, $this->stk->id);
    expect(FoodAlchemistVocabEinheit::find($this->stk->id))->toBeNull();
});

it('Kind-Team legt EIGENE Einheit an — Eltern und Geschwister sehen sie nicht', function () {
    $this->vocab->createEinheit($this->childA, ['slug' => 'kiste', 'display_de' => 'Kiste']);

    expect($this->vocab->listEinheiten($this->childA)->pluck('slug'))->toContain('kiste')
        ->and($this->vocab->listEinheiten($this->rootTeam)->pluck('slug'))->not->toContain('kiste')
        ->and($this->vocab->listEinheiten($this->childB)->pluck('slug'))->not->toContain('kiste');
});
