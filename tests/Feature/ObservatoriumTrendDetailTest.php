<?php

use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Observatorium\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistTrend;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendBeleg;
use Platform\FoodAlchemist\Services\ObservatoriumService;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Trend-Details im Observatorium-Radar für alle (Dominique 08.11.2026: Mandanten sahen nur Name und Belegzahl). Inhalt vom Master, Rechte am
 * eigenen Team, nur freigegebene Radar-Trends. Testdaten „TEST-TD“, alle Werte erfunden.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL TEST-TD', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    $this->kurator = $this->makeUser($this->master, 'Kurator TEST-TD');
    $this->leser = $this->makeUser($this->rootTeam, 'Mandant TEST-TD');   // eigener Root, nicht unter dem Master
    $this->trend = FoodAlchemistTrend::create(['team_id' => $this->master->id, 'name' => 'TEST-TD Fermentation', 'slug' => 'test-td-fermentation',
        'definition' => 'TEST-TD Definition: Fermentiertes aus eigener Herstellung.', 'typ' => 'hype', 'ebene' => 'mode', 'kategorie' => 'food',
        'status' => 'auf_radar', 'konfidenz' => 'mittel', 'ableitung' => ['kurzfazit' => 'TEST-TD Kurzfazit', 'bedeutung' => [], 'impulse' => []]]);
    FoodAlchemistTrendBeleg::create(['team_id' => $this->master->id, 'trend_id' => $this->trend->id, 'quelle' => 'presse',
        'titel' => 'TEST-TD Beleg Fachblatt', 'url' => 'https://beleg.beispiel.test/td', 'notiz' => 'TEST-TD interne Notiz', 'beobachtet_am' => '2026-10-01']);
    $this->entwurf = FoodAlchemistTrend::create(['team_id' => $this->master->id, 'name' => 'TEST-TD Entwurf', 'slug' => 'test-td-entwurf',
        'typ' => 'trend', 'ebene' => 'mode', 'status' => 'gesichtet']);
});

it('Mandant öffnet das Detail eines freigegebenen Master-Trends: Definition, Was heißt das, Belege — keine interne Notiz, kein Trend-Research-Link', function () {
    $this->actingAs($this->leser);

    Livewire::test(Index::class, ['ansicht' => 'radar'])
        ->assertSeeHtml('data-observatorium-trend-oeffnen="'.$this->trend->id.'"')
        ->assertDontSee(route('foodalchemist.trendradar.index', ['ansicht' => 'liste', 'trend' => $this->trend->id]), false)
        ->call('trendOeffnen', $this->trend->id)
        ->assertSeeHtml('data-observatorium-trend-detail="'.$this->trend->id.'"')
        ->assertSee('TEST-TD Definition: Fermentiertes aus eigener Herstellung.')
        ->assertSee('TEST-TD Kurzfazit')
        ->assertSee('TEST-TD Beleg Fachblatt')->assertSeeHtml('href="https://beleg.beispiel.test/td"')
        ->assertDontSee('TEST-TD interne Notiz')
        ->assertDontSeeHtml('data-observatorium-trend-research')
        ->call('trendSchliessen')->assertDontSeeHtml('data-observatorium-trend-detail');
});

it('nicht freigegebener Trend: weder im Radar noch als Detail (404)', function () {
    $this->actingAs($this->leser);

    Livewire::test(Index::class, ['ansicht' => 'radar'])->assertDontSee('TEST-TD Entwurf')
        ->call('trendOeffnen', $this->entwurf->id)->assertNotFound();
    expect(fn () => app(ObservatoriumService::class)->radarTrend($this->rootTeam, $this->entwurf->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});

it('Kurator: Detail plus Link in Trend Research', function () {
    $this->actingAs($this->kurator);

    Livewire::test(Index::class, ['ansicht' => 'radar'])->call('trendOeffnen', $this->trend->id)
        ->assertSee('TEST-TD Beleg Fachblatt')->assertSeeHtml('data-observatorium-trend-research');
});

it('Handy: Karte öffnet das Detail', function () {
    $this->actingAs($this->leser);

    Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test(Index::class, ['ansicht' => 'radar'])
        ->assertSeeHtml('data-observatorium-trend-oeffnen="'.$this->trend->id.'"')
        ->call('trendOeffnen', $this->trend->id)->assertSee('TEST-TD Definition: Fermentiertes aus eigener Herstellung.');
});
