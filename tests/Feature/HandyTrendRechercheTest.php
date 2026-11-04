<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Trendradar\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Handy Recherche TEST'));
    FoodAlchemistTrendQuelle::create(['team_id' => $this->rootTeam->id, 'name' => 'Fermentation Handy TEST', 'url' => 'https://news.google.com/rss/search?q=%28%22Kimchi%22%29%20when%3A30d&hl=de&gl=DE', 'art' => 'presse', 'feed_typ' => 'rss', 'abruf_weg' => 'plattform', 'rhythmus' => 'monatlich', 'aktiv' => false]);
    FoodAlchemistTrendQuelle::create(['team_id' => $this->childA->id, 'name' => 'Fremde Quelle TEST', 'url' => 'https://example.org/feed', 'art' => 'presse', 'feed_typ' => 'rss', 'abruf_weg' => 'plattform', 'rhythmus' => 'monatlich', 'aktiv' => true]);
});

it('Handy zeigt Suchthemen und Quellen einschließlich Pausenstatus ohne Schreibaktionen', function () {
    $ui = Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test(Index::class)
        ->assertSee('Suchthemen')->assertSee('Quellen')->assertSee('Eingang')->assertSee('Auswertung');
    $ui->set('handyReiter', 'beobachten')->assertSeeHtml('data-fa-handy-recherche="beobachten"')
        ->assertSee('Kimchi')->assertSee('Pausiert')->assertDontSee('Fremde Quelle TEST')
        ->assertDontSee('Beobachtung starten')->assertDontSee('Änderungen speichern');
    $ui->set('handyReiter', 'quellen')->assertSee('Automatischer Plattform-Abruf')->assertDontSee('Fremde Quelle TEST');
});

it('Handy erlaubt direkte Suchthemen-Links und zeigt Auswertungsstatus und Eingang', function () {
    $ui = Livewire::withCookie(HandyLesemodus::COOKIE, '1')->withQueryParams(['ansicht' => 'beobachten'])->test(Index::class)
        ->assertSet('handyReiter', 'beobachten')->assertSee('Kimchi');
    $ui->set('handyReiter', 'automatik')->assertSee('Auswertungsweg')->assertSee('Plattform-KI für automatische Abrufe')->assertSee('Automatik-Status');
    $ui->set('handyReiter', 'eingang')->assertSee('Noch keine passenden Artikel');
});
