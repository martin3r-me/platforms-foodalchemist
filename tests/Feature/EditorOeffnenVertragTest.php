<?php

use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\Browser as RecipeBrowser;
use Platform\FoodAlchemist\Livewire\Recipes\RecipeModal;
use Platform\FoodAlchemist\Livewire\Verkauf\Browser as VkBrowser;
use Platform\FoodAlchemist\Livewire\Verkauf\VkModal;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MVP-045 (Audit 23): Der Namensklick lud den Editor — Titel und Formular waren korrekt
 * gefüllt — aber der Dialog blieb unsichtbar (`offsetParent = false`). Bearbeiten war damit
 * über den angekündigten Weg faktisch unmöglich.
 *
 * Ursache: die Sichtbarkeit hing allein am lokalen Alpine-`open` des Modal-Bausteins, gesetzt
 * über einen `window`-Listener. Beim Livewire-Morph initialisiert die Alpine-Wurzel neu und
 * `open` fällt auf `false` zurück; das Serverflag `istOffen` steuerte gar nichts. Der Fix macht
 * den Serverzustand zur Wahrheit — ein Re-Render stellt ihn her statt ihn zu verlieren.
 *
 * Warum das hier als VERHALTEN geprüft wird und nicht als Quelltext-Muster: derselbe Fehler gab
 * es 2026-06-12 schon einmal (ignorierter `.dot`-Modifier, „kein Modal konnte je per
 * Livewire-Event öffnen"). Der Regressionsschutz von damals prüft Quelltext-Strings — und genau
 * deshalb konnte der Effekt über einen anderen Weg zurückkehren.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Root User');
    $this->actingAs($this->user);
});

it('Basisrezept-Editor rendert seinen Offen-Zustand, nicht nur ein Serverflag (MVP-045)', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'BBQ Texas');

    $c = Livewire::test(RecipeModal::class);

    // Geschlossen: der Dialog darf nicht offen gerendert werden.
    expect($c->html())->not->toContain('open: true');

    $c->dispatch('recipe-modal.oeffnen', id: $rezept->id)
        ->assertSet('istOffen', true)
        ->assertSee('BBQ Texas');

    // DAS ist der Kern des Befunds: Formular geladen UND Dialog sichtbar gerendert.
    expect($c->html())->toContain('open: true');

    // Rückweg: hartes Schließen räumt den Zustand auch im Markup ab.
    $c->dispatch('modal.closed', name: 'recipe-modal')
        ->assertSet('istOffen', false);

    expect($c->html())->not->toContain('open: true');
});

it('Gerichte-Editor rendert seinen Offen-Zustand ebenso (MVP-045, bei Gerichten reproduziert)', function () {
    $gericht = $this->makeRecipe($this->rootTeam, 'Käseplatte', ['is_sales_recipe' => true]);

    $c = Livewire::test(VkModal::class);
    expect($c->html())->not->toContain('open: true');

    $c->dispatch('vk-modal.oeffnen', id: $gericht->id)
        ->assertSet('istOffen', true);

    expect($c->html())->toContain('open: true');
});

it('Namensklick im Browser fordert genau diesen Editor an', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'BBQ Texas');
    $gericht = $this->makeRecipe($this->rootTeam, 'Käseplatte', ['is_sales_recipe' => true]);

    // Namensklick = Editor; die Zeilenauswahl fürs Detailpanel passiert zusätzlich.
    Livewire::test(RecipeBrowser::class)
        ->call('bearbeite', $rezept->id)
        ->assertSet('recipeId', $rezept->id)
        ->assertDispatched('recipe-modal.oeffnen', id: $rezept->id);

    Livewire::test(VkBrowser::class)
        ->call('bearbeite', $gericht->id)
        ->assertDispatched('vk-modal.oeffnen', id: $gericht->id);
});

it('Modal-Baustein bleibt für rein Alpine-gesteuerte Aufrufer geschlossen (Rückwärtskompatibilität)', function () {
    // Ohne :open-Prop verhält sich der Baustein wie bisher: geschlossen, per Window-Event
    // steuerbar. Sonst würde der Fix Dialoge aufreißen, die niemand geöffnet hat.
    $html = Blade::render('<x-foodalchemist::modal name="probe-modal" title="Probe">Inhalt</x-foodalchemist::modal>');

    expect($html)->toContain('open: false')
        ->and($html)->not->toContain('open: true')
        // Die addEventListener-Brücke ist der Vertrag aus dem UI-Audit 2026-06-12 und bleibt.
        ->and($html)->toContain("addEventListener('modal.open'");
});
