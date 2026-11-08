<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Angebote\Index as Angebote;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Services\InhaltsFreigabeService;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Support\HandyNavigation;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\Support\SeedsWareneingang;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class, SeedsWareneingang::class);

/**
 * Angebote am Handy (Dominique 08.11.2026: „wie beim Konfigurator und Foodbook“): Eintrag unter „Mehr“, Karten mit Kunde/Anlass/
 * Datum/Gäste, Status und Angebotssumme, Suche und Status-Chips; Antippen = Live-Vorschau, „Dokument“ = Angebots-Dokument.
 * Schreibendes serverseitig gesperrt; Spec 84 E8 (Oberteam-Angebote bei leerer Hülle unsichtbar) gilt auch am Handy.
 */
beforeEach(function () {
    $this->seedWareneingang();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->a = FoodAlchemistAngebot::create(['team_id' => $this->rootTeam->id, 'name' => 'Sommerfest HANDY', 'status' => 'angebot',
        'occasion' => 'Firmenfeier', 'personen' => 120, 'event_date' => '2026-12-04', 'total_price' => 12345.5]);
    $this->b = FoodAlchemistAngebot::create(['team_id' => $this->rootTeam->id, 'name' => 'Winterball HANDY', 'status' => 'anfrage', 'personen' => 40]);
});

function handyAng()
{
    return Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test(Angebote::class);
}

it('steht unter „Mehr“ vor den Konfiguratoren', function () {
    $mehr = collect(HandyNavigation::fuer($this->user)['mehr'])->pluck('route')->values();
    expect($mehr)->toContain('foodalchemist.angebote.index')
        ->and($mehr->search('foodalchemist.angebote.index'))->toBeLessThan($mehr->search('foodalchemist.angebote.konfiguratoren'));
});

it('am Handy: Karten mit Anlass, Datum, Gäste, Status und Summe; Vorschau- und Dokument-Link; kein Anlegen. Am Computer unverändert', function () {
    handyAng()->assertSeeHtml('data-fa-handy-angebote')
        ->assertSee('Sommerfest HANDY')->assertSee('Firmenfeier · 04.12.2026 · 120 Gäste')->assertSee('Angebot')
        ->assertSee('12.345,50')->assertSee('Noch kein Preis')
        ->assertSeeHtml('href="' . route('foodalchemist.angebote.praesentation', $this->a->id) . '"')
        ->assertSeeHtml('href="' . route('foodalchemist.angebote.dokument', $this->a->id) . '"')
        ->assertDontSee('Neue Anfrage');

    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(Angebote::class)->assertDontSeeHtml('data-fa-handy-angebote');
});

it('Suche und Status-Chip filtern', function () {
    handyAng()->set('search', 'Winter')->assertSeeHtml('data-fa-handy-angebot="' . $this->b->id . '"')->assertDontSeeHtml('data-fa-handy-angebot="' . $this->a->id . '"')
        ->set('search', '')->call('waehleStatus', 'angebot')
        ->assertSeeHtml('data-fa-handy-angebot="' . $this->a->id . '"')->assertDontSeeHtml('data-fa-handy-angebot="' . $this->b->id . '"');
});

it('Server-Sperre: nur Blättern und Status-Filter; Anlegen und Bearbeiten nein', function () {
    request()->cookies->set(HandyLesemodus::COOKIE, '1');
    foreach (['waehleStatus', 'nextPage', 'previousPage'] as $m) {
        expect(HandyLesemodus::abgewiesen(new Angebote, $m))->toBeFalse($m);
    }
    foreach (['neu', 'waehle'] as $m) {
        expect(HandyLesemodus::abgewiesen(new Angebote, $m))->toBeTrue($m);
    }
    request()->cookies->set(HandyLesemodus::COOKIE, '0');
});

it('Spec 84 E8 auch am Handy: Unter-Team mit leerer Hülle sieht die Angebote des Oberteams nicht', function () {
    app(InhaltsFreigabeService::class)->setzeErbtAlles($this->rootTeam, $this->childA->id, false, $this->inhaber);
    $kind = $this->makeUser($this->childA);
    $this->actingAs($kind);

    handyAng()->assertSeeHtml('data-fa-handy-angebote')->assertDontSee('Sommerfest HANDY')->assertDontSee('Winterball HANDY');
});
