<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MVP-027 (Audit 23, P0): Der Foodbook-Editor lud Kapitel- und Block-Daten über ungescopte
 * `find()` in public Livewire-Properties (kapitelForm/blockForm). Eine manipulierte fremde
 * Kapitel-/Block-ID prefillte damit Titel, Kundentext, Preise und interne Bemerkungen eines
 * anderen Teams — ein reines Leseleck (die nachgelagerten Writes sind über
 * FoodbookService::ownedKapitel/ownedBlock geschützt, das Prefill davor war es nicht).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    // Fremdes Foodbook (Team B) mit sensiblem Inhalt.
    $fremdBuch = $this->makeFoodbook($this->childB, 'Geheim-Buch');
    $this->fremdKapitel = $this->makeChapter($fremdBuch, [
        'title' => 'Geheim-Kapitel', 'consumer_title' => 'Nur für Team B',
        'price_mode' => 'fix', 'price_per_person' => 199,
    ]);
    $this->fremdBlock = $this->makeFoodbookBlock($this->fremdKapitel, [
        'label' => 'Geheim-Block', 'customer_text' => 'Vertraulicher Kundentext',
        'interne_bemerkung' => 'Interne Marge-Notiz B',
    ]);

    // Eigenes Foodbook (Team A).
    $eigenBuch = $this->makeFoodbook($this->childA, 'Mein Buch');
    $this->eigenKapitel = $this->makeChapter($eigenBuch, ['title' => 'Mein Kapitel']);
    $this->eigenBlock = $this->makeFoodbookBlock($this->eigenKapitel, ['label' => 'Mein Block']);

    $this->actingAs($this->makeUser($this->childA, 'Kind A User'));
});

it('prefillt kein fremdes Kapitel in das Formular (MVP-027)', function () {
    Livewire::test(Index::class)
        ->call('kapitelWaehle', $this->fremdKapitel->id)
        ->assertSet('kapitelForm', fn ($f) => empty($f['consumer_title'] ?? '') && ($f['title'] ?? '') !== 'Geheim-Kapitel');
});

it('prefillt keinen fremden Block in das Formular (MVP-027)', function () {
    Livewire::test(Index::class)
        ->call('blockBearbeiten', $this->fremdBlock->id)
        ->assertSet('editBlockId', fn ($id) => $id !== $this->fremdBlock->id)
        ->assertSet('blockForm', fn ($f) => ($f['customer_text'] ?? '') !== 'Vertraulicher Kundentext'
            && ($f['interne_bemerkung'] ?? '') !== 'Interne Marge-Notiz B');
});

it('prefillt das EIGENE Kapitel weiterhin', function () {
    Livewire::test(Index::class)
        ->call('kapitelWaehle', $this->eigenKapitel->id)
        ->assertSet('selectedKapitelId', $this->eigenKapitel->id)
        ->assertSet('kapitelForm', fn ($f) => ($f['title'] ?? '') === 'Mein Kapitel');
});

it('prefillt den EIGENEN Block weiterhin', function () {
    Livewire::test(Index::class)
        ->call('blockBearbeiten', $this->eigenBlock->id)
        ->assertSet('editBlockId', $this->eigenBlock->id)
        ->assertSet('blockForm', fn ($f) => ($f['label'] ?? '') === 'Mein Block');
});
