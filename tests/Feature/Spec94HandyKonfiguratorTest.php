<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Angebote\Konfiguratoren;
use Platform\FoodAlchemist\Models\FoodAlchemistAngebot;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Support\HandyNavigation;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 94 Stufe 6 · Konfigurator am Handy: Eintrag unter „Mehr“, Liste mit Link-Status und offenen Anfragen, Detail mit
 * Preisen, veröffentlichen/zurückziehen mit „Anfragen gehen an“ und „Gültig bis“; Fremdteam unsichtbar; Bearbeiten bleibt gesperrt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $svc = app(KonfiguratorService::class);
    $this->k = $svc->anlegen($this->rootTeam, ['name' => 'Gala HANDY', 'kunden_titel' => 'Diva Handy']);
    $c = FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => 'Culture', 'kind' => 'concept', 'status' => 'active', 'price_per_person_cache' => 50, 'ek_per_person_cache' => 15]);
    $svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'haupt', 'dinner_concept_id' => $c->id, 'kunden_titel' => 'Culture']);
    $svc->paketAnlegen($this->rootTeam, $this->k->id, ['von_pax' => 100, 'bis_pax' => 199]);
    foreach (['anfrage', 'anfrage', 'in_arbeit'] as $i => $status) {
        FoodAlchemistAngebot::create(['team_id' => $this->rootTeam->id, 'name' => "Anfrage {$i}", 'status' => $status, 'konfigurator_id' => $this->k->id, 'personen' => 120]);
    }
});

function handyKf()
{
    return Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test(Konfiguratoren::class);
}

it('steht unter „Mehr“ und die Handy-Route ist erreichbar', function () {
    expect(collect(HandyNavigation::fuer($this->user)['mehr'])->pluck('route'))->toContain('foodalchemist.angebote.konfiguratoren');
    // Voll-Seitenaufbau braucht Core-Tabellen außerhalb des Test-Harness (oauth); wie die übrigen Handy-Tests: Route → Komponente
    $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('foodalchemist.angebote.konfiguratoren');
    expect($route)->not->toBeNull()->and($route->getActionName())->toContain(Konfiguratoren::class)
        ->and(\Platform\FoodAlchemist\Support\FaBereiche::fuerRoute('foodalchemist.angebote.konfiguratoren'))->toBe('angebote');
});

it('Liste: Kundentitel, Link-Status und Zahl offener Anfragen (nur Status anfrage)', function () {
    handyKf()->assertSeeHtml('data-fa-handy-konfiguratoren')->assertSee('Diva Handy')->assertSee('Kein Link')
        ->assertSeeHtml('data-fa-handy-kf-anfragen="' . $this->k->id . '"')->assertSee('2 Anfragen')
        ->assertDontSee('Neuer Konfigurator');   // kein Anlegen am Handy
});

it('Detail: veröffentlichen mit „Anfragen gehen an“ und „Gültig bis“, dann zurückziehen; offene Anfragen verlinkt', function () {
    $bis = now()->addMonths(2)->format('Y-m-d');
    $t = handyKf()->call('waehle', $this->k->id)
        ->assertSeeHtml('data-fa-handy-kf-kennzahlen')->assertSee('Preise je Paket')->assertSee('Culture Dinner')->assertSee('Reihenfolge im Link: Zeitfenster zuerst')
        ->assertSeeHtml('data-fa-handy-kf-anfrage=')
        ->set('handyLink', ['verantwortlich_user_id' => (string) $this->user->id, 'gueltig_bis' => $bis])
        ->call('handyVeroeffentlichen')->assertSet('fehler', null)->assertSeeHtml('data-fa-handy-kf-url');
    $k = $this->k->fresh();
    expect($k->isPresentationLive())->toBeTrue()->and($k->verantwortlich_user_id)->toBe($this->user->id)
        ->and($k->gueltig_bis->format('Y-m-d'))->toBe($bis)->and($k->presentation_expires_at->format('Y-m-d'))->toBe($bis);

    $t->call('handyZurueckziehen')->assertSet('fehler', null);
    expect($this->k->fresh()->isPresentationLive())->toBeFalse();
});

it('Fremdteam: ein Geschwister sieht den Konfigurator nicht und kann ihn nicht veröffentlichen', function () {
    $fremd = app(KonfiguratorService::class)->anlegen($this->childA, ['name' => 'Geheim A']);
    $this->actingAs($this->makeUser($this->childB, 'B'));
    handyKf()->assertDontSee('Geheim A')->call('waehle', $fremd->id)->assertDontSeeHtml('data-fa-handy-kf-kennzahlen')
        ->call('handyVeroeffentlichen')->assertSet('fehler', fn ($f) => $f !== null);
    expect($fremd->fresh()->presentation_enabled)->toBeFalse();
});

it('am Handy gesperrt: anlegen geht nicht; am Computer unverändert', function () {
    $vorher = \Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator::count();
    handyKf()->call('neu');
    expect(\Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator::count())->toBe($vorher);
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(Konfiguratoren::class)->assertSee('Neuer Konfigurator')->assertDontSeeHtml('data-fa-handy-konfiguratoren');
});
