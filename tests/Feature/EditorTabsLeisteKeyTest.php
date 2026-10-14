<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Foodbook (demo): Klick auf ein Kapitel markierte es im Baum, aber der Reiter „Speisen" erschien nie. Ursache: die Reiterleiste des Bausteins
 * editor-tabs trägt wire:ignore (seit 06.10.) und blieb nach dem Kapitelwechsel unverändert, obwohl die Reiterliste dynamisch ist
 * ('speisen' nur mit gewähltem Kapitel); ihr wire:key blieb gleich. Jetzt keyed die Leiste über die SICHTBAREN Reiter.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $buch = $this->makeFoodbook($this->rootTeam, 'Sommerfest');
    $this->kapitel = $this->makeChapter($buch, ['title' => 'Dessert']);
    $this->buch = $buch;
    $this->actingAs($this->makeUser($this->rootTeam, 'Küchenchef'));
    $this->leistenKey = function (string $html): ?string {
        // die ignorierte Reiterleiste (Marker data-fa-editor-tabs-leiste) und ihr wire:key
        return preg_match('/<div[^>]*wire:ignore[^>]*wire:key="([^"]+)"[^>]*data-fa-editor-tabs-leiste/', $html, $m) ? $m[1] : null;
    };
});

it('★ nach dem Kapitelklick enthält das HTML den Reiter „Speisen" UND die Leiste trägt einen anderen wire:key als vorher', function () {
    $t = Livewire::test(Index::class)->call('waehle', $this->buch->id);
    $vorher = $t->html();
    $keyVorher = ($this->leistenKey)($vorher);

    expect($vorher)->not->toContain("data-fb-tab=\"speisen\"")
        ->and($keyVorher)->not->toBeNull();

    $t->call('kapitelWaehle', $this->kapitel->id);
    $nachher = $t->html();
    $keyNachher = ($this->leistenKey)($nachher);

    expect($nachher)->toContain('data-fb-tab="speisen"')
        ->and($keyNachher)->not->toBeNull()->and($keyNachher)->not->toBe($keyVorher);
});

it('bei gleicher Reiter-Menge bleibt der Schlüssel stabil (wire:ignore schützt weiter vor dem Morph)', function () {
    $t = Livewire::test(Index::class)->call('waehle', $this->buch->id)->call('kapitelWaehle', $this->kapitel->id);
    $erster = ($this->leistenKey)($t->html());

    $t->call('kapitelWaehle', $this->kapitel->id);   // gleiche Auswahl → gleiche Menge an Reitern

    expect(($this->leistenKey)($t->html()))->toBe($erster);
});

it('der Sprung-Handler fb-goto versucht es nach dem Austausch der Leiste erneut', function () {
    $t = Livewire::test(Index::class)->call('waehle', $this->buch->id);

    expect($t->html())->toContain('setTimeout(setze, 200)')->toContain('$nextTick(setze)');
});
