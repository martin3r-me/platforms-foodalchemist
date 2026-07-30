<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\Browser as RecipeBrowser;
use Platform\FoodAlchemist\Livewire\Verkauf\Browser as VkBrowser;
use Platform\FoodAlchemist\Support\Labels;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * MVP-022 / MVP-024 (Audit 23): Statusaktionen in beiden Rezept-Browsern fingen jede
 * Exception in einem leeren `catch` — schlug etwas fehl, bekam der Nutzer nichts. Die
 * sichtbare Auswahl blieb auf dem Wert stehen, der nie gespeichert wurde.
 *
 * MVP-023 / MVP-024: Interne Enum-Rohwerte (`from_scratch`, `suess`, `high`) standen in der
 * deutschen UI; der Fertigungs-Filter zeigte sogar „from scratch" auf Englisch. Beide Browser
 * duplizierten die Label-Logik.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam, 'Root User');
    $this->actingAs($this->user);
});

// ── MVP-022: Statusfehler wird sichtbar, kein stiller Schluck ────────────────

it('meldet einen fehlgeschlagenen Statuswechsel sichtbar statt ihn zu schlucken (MVP-022)', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'BBQ Texas', ['status' => 'approved']);

    Livewire::test(RecipeBrowser::class)
        ->assertSet('statusFehler', null)
        // Unbekannter Zielstatus: früher schluckte der leere catch das ohne jedes Feedback.
        ->call('statusSetzen', $rezept->id, 'phantasiestatus')
        ->assertSet('statusFehler', fn ($m) => is_string($m) && $m !== '');

    // Rollback: der persistierte Wert steht unverändert.
    expect($rezept->fresh()->status->value)->toBe('approved');
});

it('meldet einen Statuswechsel an einem geerbten Rezept sichtbar (MVP-022)', function () {
    // Root-Rezept, bearbeitet aus dem Kind-Team: sichtbar, aber nicht kuratierbar.
    $rootRezept = $this->makeRecipe($this->rootTeam, 'Master-Sauce', ['status' => 'draft']);
    $this->actingAs($this->makeUser($this->childA, 'Kind A User'));

    Livewire::test(RecipeBrowser::class)
        ->call('statusSetzen', $rootRezept->id, 'approved')
        ->assertSet('statusFehler', fn ($m) => is_string($m) && $m !== '');

    expect($rootRezept->fresh()->status->value)->toBe('draft');
});

it('ein gültiger Statuswechsel läuft weiter durch und lässt den Fehler leer', function () {
    $rezept = $this->makeRecipe($this->rootTeam, 'BBQ Texas', ['status' => 'draft']);

    Livewire::test(RecipeBrowser::class)
        ->call('statusSetzen', $rezept->id, 'review')
        ->assertSet('statusFehler', null);

    expect($rezept->fresh()->status->value)->toBe('review');
});

it('auch der Gerichte-Browser meldet einen fehlgeschlagenen Statuswechsel (MVP-024)', function () {
    $gericht = $this->makeRecipe($this->rootTeam, 'Käseplatte', ['is_sales_recipe' => true, 'status' => 'approved']);

    Livewire::test(VkBrowser::class)
        ->call('statusSetzen', $gericht->id, 'phantasiestatus')
        ->assertSet('statusFehler', fn ($m) => is_string($m) && $m !== '');

    expect($gericht->fresh()->status->value)->toBe('approved');
});

// ── MVP-023/024: zentrale deutsche Labels, keine Rohwerte ────────────────────

it('Labels übersetzt die internen Enumwerte einheitlich', function () {
    expect(Labels::fertigung('from_scratch'))->toBe('Frisch')
        ->and(Labels::fertigung('teilfertig'))->toBe('Teilfertig')
        ->and(Labels::fertigung('convenience'))->toBe('Convenience')
        ->and(Labels::geschmack('suess'))->toBe('Süß')
        ->and(Labels::geschmack('herzhaft'))->toBe('Herzhaft')
        ->and(Labels::konfidenz('high'))->toBe('Hoch')
        ->and(Labels::konfidenz('medium'))->toBe('Mittel')
        ->and(Labels::konfidenz('unknown'))->toBe('Unbekannt')
        // Leer/null bleibt der neutrale Strich, nie ein roher Wert.
        ->and(Labels::fertigung(null))->toBe('—')
        ->and(Labels::geschmack(''))->toBe('—')
        // Unbekannter Wert wird nicht verschluckt, sondern durchgereicht (sichtbar für Pflege).
        ->and(Labels::fertigung('etwas_neues'))->toBe('etwas_neues');
});

it('der Basisrezept-Browser zeigt keine rohen Enumwerte mehr (MVP-023)', function () {
    $this->makeRecipe($this->rootTeam, 'BBQ Texas', [
        'production_depth' => 'from_scratch', 'taste_direction' => 'suess', 'allergens_confidence' => 'high',
    ]);

    $html = Livewire::test(RecipeBrowser::class)->html();

    // Der Rohwert steht nicht mehr als Zellinhalt da; das Label schon.
    expect($html)->toContain('Frisch')
        ->and($html)->toContain('Süß')
        // „from scratch" (englischer Filter-Rest) ist verschwunden.
        ->and($html)->not->toContain('from scratch')
        ->and($html)->not->toContain('>from_scratch<');
});
