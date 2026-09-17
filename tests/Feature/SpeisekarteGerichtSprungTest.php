<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Speisekarte\Index as SpeisekarteIndex;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Bug-Runde 2026-09-17 #1 — aus der Speisekarte ins Gericht. Erste Fassung war ein Link in
 * einen neuen Tab; Dominique will den Editor ÜBER der Karte. Geprüft wird deshalb, dass die
 * Zeile das Editor-Event trägt und die Karte die passenden Modal-Komponenten mitbringt —
 * ohne beides klickt man ins Leere, genau der gemeldete Effekt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->childA);
    $this->actingAs($this->user);
    $registry = app(ToolRegistry::class);
    $kontext = new ToolContext($this->user, $this->childA);

    $this->gericht = $this->makeRecipe($this->childA, 'HG Zander', ['is_sales_recipe' => true, 'sales_net' => 29.0]);
    $this->karteId = (int) $registry->get('foodalchemist.speisekarten.POST')->execute(['name' => 'Abendkarte'], $kontext)->data['speisekarte']['id'];
    $rubrikId = (int) $registry->get('foodalchemist.speisekarte_rubrik.POST')->execute([
        'speisekarte_id' => $this->karteId, 'title' => 'Fisch', 'art' => 'speisen',
    ], $kontext)->data['rubrik']['id'];
    $registry->get('foodalchemist.speisekarte_positionen.POST')->execute([
        'rubrik_id' => $rubrikId, 'type' => 'gericht_ref', 'sales_recipe_id' => $this->gericht->id,
    ], $kontext);
});

it('die Gericht-Zeile öffnet den VK-Editor über der Karte statt in einem neuen Tab', function () {
    $html = Livewire::test(SpeisekarteIndex::class, ['karteId' => $this->karteId])->html();

    // Das Gericht ist als anklickbarer Einstieg da …
    expect($html)->toContain('data-sk-pos-oeffnen');
    expect($html)->toContain("vk-modal.oeffnen");
    expect($html)->toContain('{ id: ' . $this->gericht->id . ' }');

    // … und zwar OHNE Absprung in einen neuen Tab (der Vorgänger dieser Fassung).
    // Nicht auf den Routen-NAMEN prüfen: der steht auch in der Seiten-Navigation, der Test
    // wäre dann rot, ohne dass die Zeile etwas damit zu tun hat (einmal reingelaufen).
    expect($html)->not->toContain('gerichte?rezept=');
    expect($html)->not->toMatch('/<a[^>]*data-sk-pos-oeffnen/');
});

it('die Karte bindet die Editor-Modale ein — ohne sie läuft der Klick ins Leere', function () {
    $html = Livewire::test(SpeisekarteIndex::class, ['karteId' => $this->karteId])->html();

    // Die Modale rendern als eigene Livewire-Komponenten in die Seite.
    expect($html)->toContain('vk-modal');
    expect($html)->toContain('recipe-modal');
});
