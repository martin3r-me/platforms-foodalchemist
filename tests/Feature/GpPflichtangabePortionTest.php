<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Gps\GpModal;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\GpNamingService;
use Platform\FoodAlchemist\Services\GpPaket\GpPaketValidator;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * „Name aus Feldern“ vollständig (Entscheid Dominique 25.10.2026): `pflichtangabe` und `portion` sind GP-Spalten (vorher nur Builder-Eingaben ohne
 * Spalte), Anlage und Bearbeiten speichern sie, Teil-Schreiber lassen sie stehen, der Name ist aus den gespeicherten Feldern rekonstruierbar,
 * und das Paket (Whitelist, Schreibfelder, Hash) nimmt flavor/zusatz/pflichtangabe/portion mit.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Root User');
    $this->actingAs($this->user);
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.bearbeitungssperre' => false]);
    $this->naming = app(GpNamingService::class);
    $this->felder = ['hauptzutat' => 'Karotten', 'condition' => 'frisch', 'processing' => 'geputzt', 'form' => 'ganz'];
});

it('Anlage speichert pflichtangabe und portion; Name aus den GESPEICHERTEN Feldern ergibt denselben Namen (Rundlauf)', function () {
    $gp = $this->naming->createGp($this->rootTeam, array_merge($this->felder, ['pflichtangabe' => 'mini', 'portion' => '100 g']));

    expect($gp->processing)->toBe('geputzt')->and($gp->form)->toBe('ganz')->and($gp->main_ingredient_display)->toBe('Karotten')
        ->and($gp->pflichtangabe)->toBe('mini')->and($gp->portion)->toBe('100 g')->and($gp->name)->toBe('Karotten: frisch, geputzt, ganz, 100 g pro Stück, mini');

    $aus = $this->naming->renderGpName([
        'hauptzutat' => $gp->main_ingredient_display, 'condition' => $gp->condition, 'processing' => $gp->processing, 'form' => $gp->form,
        'portion' => $gp->portion, 'pflichtangabe' => $gp->pflichtangabe,
    ]);
    expect($aus)->toBe($gp->name);
});

it('Bearbeiten ersetzt oder leert die Felder; ein Teil-Schreiber ohne sie lässt die Werte stehen', function () {
    $gp = $this->naming->createGp($this->rootTeam, $this->felder + ['pflichtangabe' => 'mini', 'portion' => '100 g']);

    $teil = $this->naming->updateGp($this->rootTeam, $gp, ['name' => $gp->name, 'condition' => 'frisch']);
    expect($teil->pflichtangabe)->toBe('mini')->and($teil->portion)->toBe('100 g');

    $neu = $this->naming->updateGp($this->rootTeam, $teil->refresh(), array_merge($this->felder, ['pflichtangabe' => 'Größe M', 'portion' => '']));
    expect($neu->pflichtangabe)->toBe('Größe M')->and($neu->portion)->toBeNull();
});

it('Modal: Speichern und Laden mit pflichtangabe und portion (Vorschau zeigt sie im Namen)', function () {
    Livewire::test(GpModal::class)->call('oeffnen')
        ->set('builder.hauptzutat', 'Karotten')->set('builder.condition', 'frisch')->set('builder.processing', 'ganz')->set('builder.pflichtangabe', 'mini')->set('builder.portion', '100 g')
        ->assertSeeHtml('Karotten: frisch, ganz, 100 g pro Stück, mini')
        ->call('speichern')->assertSet('fehler', null);

    $gp = FoodAlchemistGp::where('pflichtangabe', 'mini')->firstOrFail();
    expect($gp->portion)->toBe('100 g');

    Livewire::test(GpModal::class)->call('oeffnen', $gp->id)->assertSet('builder.pflichtangabe', 'mini')->assertSet('builder.portion', '100 g')
        ->set('builder.pflichtangabe', 'Größe M')->call('nameAusFeldern')->call('speichern')->assertSet('fehler', null);
    expect($gp->fresh()->pflichtangabe)->toBe('Größe M')->and($gp->fresh()->portion)->toBe('100 g');
});

it('MCP gps.POST/PUT speichern pflichtangabe und portion (Parameter im Schema, Teil-Update lässt stehen)', function () {
    $reg = app(ToolRegistry::class);
    $ctx = new ToolContext($this->user, $this->rootTeam);
    foreach (['foodalchemist.gps.POST', 'foodalchemist.gps.PUT'] as $name) {
        expect($reg->get($name)->getSchema()['properties'])->toHaveKeys(['pflichtangabe', 'portion']);
    }

    $r = $reg->get('foodalchemist.gps.POST')->execute($this->felder + ['pflichtangabe' => 'mini', 'portion' => '100 g'], $ctx);
    expect($r->success)->toBeTrue();
    $gp = FoodAlchemistGp::findOrFail($r->data['id']);
    expect($gp->pflichtangabe)->toBe('mini')->and($gp->portion)->toBe('100 g');

    $p = $reg->get('foodalchemist.gps.PUT')->execute(['id' => $gp->id, 'pflichtangabe' => 'Größe M'] + $this->felder, $ctx);
    expect($p->success)->toBeTrue()->and($gp->fresh()->pflichtangabe)->toBe('Größe M')->and($gp->fresh()->portion)->toBe('100 g');
});

it('Paket: Whitelist, Schreibfelder und Hash führen flavor, zusatz, pflichtangabe und portion', function () {
    $neu = ['flavor', 'zusatz', 'pflichtangabe', 'portion'];
    foreach ($neu as $c) {
        expect(GpPaketValidator::GP_SPALTEN)->toContain($c)->and(GpPaketValidator::WRITE_SPALTEN)->toContain($c);
    }
    $v = app(GpPaketValidator::class);
    $ctx = ['gpSpalten' => array_fill_keys(['name', 'flavor', 'zusatz', 'pflichtangabe', 'portion'], 1)];
    $w = $v->werte(['name' => 'X', 'flavor' => 'Chili', 'zusatz' => '', 'pflichtangabe' => 'mini', 'portion' => '100 g'], '01', $ctx)['wert'];

    expect($w['pflichtangabe'])->toBe('mini')->and($w['portion'])->toBe('100 g')->and($w['flavor'])->toBe('Chili')->and($w['zusatz'])->toBeNull();
    $h1 = $v->hashZeile(['name' => 'X', 'pflichtangabe' => 'mini'], $ctx);
    $h2 = $v->hashZeile(['name' => 'X', 'pflichtangabe' => 'maxi'], $ctx);
    expect($h1)->not->toBe($h2);   // eine Änderung der Pflichtangabe zählt als Änderung (Konfliktprüfung)

    // ältere Pakete ohne die Schlüssel lassen die Werte auf io stehen
    expect($v->werte(['name' => 'X'], '01', $ctx)['wert'])->not->toHaveKeys(['pflichtangabe', 'portion']);
});

it('renderGpName gibt Verarbeitung UND Zuschnitt aus (Verarbeitung zuerst); ein im Verarbeitungstext schon genannter Zuschnitt wird nicht verdoppelt', function () {
    $n = app(GpNamingService::class);

    expect($n->renderGpName(['hauptzutat' => 'Kartoffel', 'condition' => 'frisch', 'processing' => 'gegart', 'form' => 'Würfel 10 mm']))->toBe('Kartoffel: frisch, gegart, Würfel 10 mm')
        ->and($n->renderGpName(['hauptzutat' => 'Kartoffel', 'condition' => 'frisch', 'processing' => '', 'form' => 'Würfel 10 mm']))->toBe('Kartoffel: frisch, Würfel 10 mm')
        ->and($n->renderGpName(['hauptzutat' => 'Kartoffel', 'condition' => 'frisch', 'processing' => 'gegart', 'form' => '']))->toBe('Kartoffel: frisch, gegart')
        ->and($n->renderGpName(['hauptzutat' => 'Lachs', 'condition' => 'geräuchert', 'processing' => 'TK, geschnitten', 'form' => 'geschnitten']))->toBe('Lachs: geräuchert, TK, geschnitten');   // Zuschnitt schon im Text → kein Doppel
});
