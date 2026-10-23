<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Index as Einstellungen;
use Platform\FoodAlchemist\Livewire\Settings\Kalkulation;
use Platform\FoodAlchemist\Services\BearbeitungssperreService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Einstellungen E-3 (Dominique 23.10.): EIN „Bearbeiten“ je Reiter statt einer Leiste je Bereich. Der Reiter holt die Sperren aller
 * seiner Bereiche (settings.<bereich>), Speichern in einem Bereich gibt NICHT frei, „Fertig“ und Reiterwechsel geben frei; eine fremde
 * Sperre bleibt sichtbar; ohne Admin-Recht gibt es keinen Knopf.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    config(['foodalchemist.bearbeitungssperre' => true]);
    $this->admin = $this->makeUser($this->rootTeam, 'Admin', 'owner');
    $this->actingAs($this->admin);
    $this->svc = app(BearbeitungssperreService::class);
    $this->haelt = fn (string $bereich, $user = null) => $this->svc->haelt('settings.' . $bereich, (int) $this->rootTeam->id, (int) ($user ?? $this->admin)->id);
});

it('Bearbeiten sperrt alle Bereiche des Reiters, Fertig gibt alle frei', function () {
    $c = Livewire::test(Einstellungen::class)->call('tabWaehlen', 'kalkulation')->assertSeeHtml('data-reiter-bearbeiten')
        ->call('reiterBearbeiten')->assertDispatched('settings-reiter-geaendert')->assertSeeHtml('data-reiter-in-bearbeitung');
    expect(($this->haelt)('kalkulation'))->toBeTrue()->and(($this->haelt)('herstellkosten'))->toBeTrue()
        ->and(($this->haelt)('aufschlagsklassen'))->toBeTrue()->and(($this->haelt)('betriebe'))->toBeTrue()
        ->and(($this->haelt)('einkauf'))->toBeFalse();   // anderer Reiter

    $c->call('reiterFertig')->assertSeeHtml('data-reiter-bearbeiten');
    expect(($this->haelt)('kalkulation'))->toBeFalse()->and(($this->haelt)('betriebe'))->toBeFalse();
});

it('im Reiter: Bereich ohne eigene Leiste, Speichern behält die Sperre; einzeln (ohne Reiter) wie bisher', function () {
    Livewire::test(Einstellungen::class)->call('tabWaehlen', 'kalkulation')->call('reiterBearbeiten');

    Livewire::test(Kalkulation::class, ['imReiter' => true])->assertDontSeeHtml('data-settings-sperrleiste')
        ->call('speichern');
    expect(($this->haelt)('kalkulation'))->toBeTrue();   // Reiter gibt erst mit „Fertig“ frei

    Livewire::test(Kalkulation::class)->assertSeeHtml('data-settings-sperrleiste')->call('speichern');
    expect(($this->haelt)('kalkulation'))->toBeFalse();  // einzeln: Speichern gibt frei (Spec 65)
});

it('Reiterwechsel gibt die eigenen Sperren des alten Reiters frei', function () {
    Livewire::test(Einstellungen::class)->call('tabWaehlen', 'kalkulation')->call('reiterBearbeiten')->call('tabWaehlen', 'einkauf');

    expect(($this->haelt)('kalkulation'))->toBeFalse()->and(($this->haelt)('herstellkosten'))->toBeFalse();
});

it('fremde Sperre: der Bereich bleibt für mich lesbar, der Reiter nennt Bereich und Person', function () {
    $kollegin = $this->makeUser($this->rootTeam, 'Kollegin', 'owner');
    $this->svc->sperren('settings.herstellkosten', (int) $this->rootTeam->id, (int) $kollegin->id, 'Kollegin', (int) $this->rootTeam->id);

    $html = Livewire::test(Einstellungen::class)->call('tabWaehlen', 'kalkulation')->call('reiterBearbeiten')->html();
    expect($html)->toContain('data-reiter-fremd')->toContain('Herstellkosten &amp; Zuschläge: Kollegin')
        ->and(($this->haelt)('kalkulation'))->toBeTrue()->and(($this->haelt)('herstellkosten'))->toBeFalse();
});

it('ohne Admin-Recht und bei abgeschalteter Sperre gibt es keinen Reiter-Knopf', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Mitglied', 'member'));
    Livewire::test(Einstellungen::class)->call('tabWaehlen', 'kalkulation')->assertDontSeeHtml('data-reiter-bearbeiten')
        ->call('reiterBearbeiten');
    expect($this->svc->fremd('settings.kalkulation', (int) $this->rootTeam->id, (int) $this->admin->id))->toBeNull();

    $this->actingAs($this->admin);
    config(['foodalchemist.bearbeitungssperre' => false]);
    Livewire::test(Einstellungen::class)->call('tabWaehlen', 'kalkulation')->assertDontSeeHtml('data-reiter-bearbeiten');
});

it('Design: aktiver Reiter als volle Lasche, Bereich als eine Karte mit Symbol, Sprungmarken im Akzent', function () {
    $html = Livewire::test(Einstellungen::class)->call('tabWaehlen', 'kalkulation')->html();

    expect($html)->toContain('bg-[var(--fa-accent)]')->toContain('text-[var(--fa-on-accent)]')
        ->toContain('data-settings-inhalt')->toContain('bg-[var(--fa-accent-soft)]');
});
