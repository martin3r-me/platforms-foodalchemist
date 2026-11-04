<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Trendradar\Beobachten;
use Platform\FoodAlchemist\Livewire\Trendradar\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendQuelle;
use Platform\FoodAlchemist\Services\Trends\QuellenAbruf;
use Platform\FoodAlchemist\Support\GoogleNewsSuche;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->inhaber = $this->makeUser($this->rootTeam, 'Themen TEST');
    $this->actingAs($this->inhaber);
    Queue::fake();
});

it('Suchbegriffe und Ausschlüsse überstehen URL-Speichern samt Umlauten und Markt', function () {
    $form = ['begriffe' => "Mocktails\nalkoholfreie Cocktails\nMocktails", 'ausschliessen' => 'Jobs, Gewinnspiel', 'ausdruck' => '', 'markt' => 'AT', 'zeitraum' => '7d'];
    $url = GoogleNewsSuche::url($form);
    expect(GoogleNewsSuche::lesen($url))->toMatchArray(['begriffe' => "Mocktails\nalkoholfreie Cocktails", 'ausschliessen' => "Jobs\nGewinnspiel", 'markt' => 'AT', 'zeitraum' => '7d', 'ausdruck' => '']);
    expect(GoogleNewsSuche::lesen('https://evil.test/rss/search?q=test'))->toBeNull();
    $legacy = 'https://news.google.com/rss/search?q='.rawurlencode('(Koji OR Fermentation) (Küche OR Gastronomie) when:30d').'&hl=de&gl=DE';
    expect(GoogleNewsSuche::lesen($legacy)['ausdruck'])->toBe('(Koji OR Fermentation) (Küche OR Gastronomie)');
});

it('Vorschau schreibt keine Quelle und Speichern hält bearbeitete Feed-URL und Rhythmus synchron', function () {
    $abruf = Mockery::mock(QuellenAbruf::class);
    $abruf->shouldReceive('lesen')->once()->andReturn([['titel' => 'Mocktails TEST', 'url' => 'https://example.org/mocktail', 'teaser' => null, 'veroeffentlicht_am' => null]]);
    app()->instance(QuellenAbruf::class, $abruf);
    $ui = Livewire::test(Beobachten::class)
        ->set('form.name', 'Getränke TEST')->set('form.begriffe', "Mocktails\nZero Proof")
        ->call('trefferAnsehen')->assertHasNoErrors()->assertSee('Mocktails TEST');
    expect(FoodAlchemistTrendQuelle::count())->toBe(0);
    $ui->call('speichern')->assertHasNoErrors();
    $q = FoodAlchemistTrendQuelle::firstOrFail();
    expect($q->url)->toBe($q->feed_url)->and($q->rhythmus)->toBe('woechentlich')->and($q->abruf_weg)->toBe('plattform');
    $ui->call('bearbeiten', $q->id)->set('form.begriffe', 'Kombucha')->set('form.rhythmus', 'monatlich')->call('speichern');
    $q->refresh();
    expect($q->url)->toBe($q->feed_url)->and(GoogleNewsSuche::lesen($q->url)['begriffe'])->toBe('Kombucha')->and($q->rhythmus)->toBe('monatlich');
    $ui->call('pausieren', $q->id);
    expect($q->refresh()->aktiv)->toBeFalse();
});

it('Leser dürfen weder Suchaufträge speichern noch Vorschauen auslösen', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Leser TEST', 'viewer'));
    $abruf = Mockery::mock(QuellenAbruf::class);
    $abruf->shouldNotReceive('lesen');
    app()->instance(QuellenAbruf::class, $abruf);
    Livewire::test(Beobachten::class)->assertDontSee('Beobachtung starten');
    expect(fn () => app(Beobachten::class)->trefferAnsehen())->toThrow(FaRechtFehltException::class);
    expect(fn () => app(Beobachten::class)->speichern())->toThrow(FaRechtFehltException::class);
    expect(FoodAlchemistTrendQuelle::count())->toBe(0);
});

it('Quellen anderer Teams sind gegen Bearbeiten und Pausieren geschützt', function () {
    $q = FoodAlchemistTrendQuelle::create(['team_id' => $this->childA->id, 'name' => 'Fremd TEST', 'url' => GoogleNewsSuche::url(['begriffe' => 'Mochi', 'ausschliessen' => '', 'markt' => 'DE', 'zeitraum' => '30d']), 'art' => 'presse', 'feed_typ' => 'rss', 'abruf_weg' => 'plattform', 'rhythmus' => 'woechentlich', 'aktiv' => true]);
    expect(fn () => app(Beobachten::class)->bearbeiten($q->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => app(Beobachten::class)->pausieren($q->id))->toThrow(ModelNotFoundException::class);
    expect($q->refresh()->aktiv)->toBeTrue();
});

it('Themen-Reiter rendert die neue Maske im Trendradar', function () {
    Livewire::test(Index::class)
        ->call('ansichtSetzen', 'beobachten')->assertSet('ansicht', 'beobachten')
        ->assertSee('Deine Suchthemen')->assertSee('Treffer ansehen');
});

it('Ungültige Suchen werden vor Netzaufruf oder Speicherung abgewiesen', function () {
    $abruf = Mockery::mock(QuellenAbruf::class);
    $abruf->shouldNotReceive('lesen');
    app()->instance(QuellenAbruf::class, $abruf);
    Livewire::test(Beobachten::class)->set('form.name', 'Ungültig TEST')
        ->call('trefferAnsehen')->assertHasErrors('form.begriffe')
        ->set('form.begriffe', 'Mochi')->set('form.markt', 'XX')
        ->call('speichern')->assertHasErrors('form.markt');
    expect(FoodAlchemistTrendQuelle::count())->toBe(0);
    expect(GoogleNewsSuche::lesen('https://news.google.com/rss/search?q=Test&gl[]=DE'))->toBeNull();
});

it('Karten zerlegen bekannte Suchgruppen nur zur Anzeige und behalten beliebige Ausdrücke', function () {
    $a = ['begriffe' => '', 'ausdruck' => '(Fermentation OR fermentiert OR Koji) (Gastronomie OR Küche OR Lebensmittel)'];
    expect(GoogleNewsSuche::anzeige($a))->toBe(['begriffe' => ['Fermentation', 'fermentiert', 'Koji'], 'kontext' => ['Gastronomie', 'Küche', 'Lebensmittel']]);
    expect(GoogleNewsSuche::anzeige(['begriffe' => '', 'ausdruck' => 'site:example.org (Food OR Küche)']))->toBe(['begriffe' => [], 'kontext' => []]);
});

it('Editor öffnet gezielt und verwirft ungespeicherten Zustand beim Schließen', function () {
    Livewire::test(Beobachten::class)->call('neu')->assertDispatched('modal.open', name: 'suchthema-editor')
        ->set('form.name', 'Nicht gespeichert')->set('form.begriffe', 'Mochi')
        ->call('editorGeschlossen', 'anderer-dialog')->assertSet('form.name', 'Nicht gespeichert')
        ->call('editorGeschlossen', 'suchthema-editor')->assertSet('form.name', '')->assertSet('form.begriffe', '');
    expect(FoodAlchemistTrendQuelle::count())->toBe(0);
});

it('Kompakte Themenansicht rendert Schlagwörter und Suchdetails', function () {
    FoodAlchemistTrendQuelle::create(['team_id' => $this->rootTeam->id, 'name' => 'Google News: Fermentation (DE)', 'url' => 'https://news.google.com/rss/search?q='.rawurlencode('(Fermentation OR Koji) (Gastronomie OR Küche) when:30d').'&hl=de&gl=DE', 'art' => 'presse', 'feed_typ' => 'rss', 'abruf_weg' => 'plattform', 'rhythmus' => 'woechentlich', 'aktiv' => true]);
    $ui = Livewire::test(Beobachten::class)->assertSee('Fermentation')->assertSee('Im Kontext: Gastronomie · Küche')->assertSee('Thema hinzufügen')->assertSee('Details zur Suche');
    if ($path = getenv('FA_UI_PREVIEW_PATH')) {
        file_put_contents($path, $ui->html());
    }
});
