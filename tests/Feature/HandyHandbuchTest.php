<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Recipes\Browser as RezeptBrowser;
use Platform\FoodAlchemist\Livewire\Recipes\DetailPanel as RezeptDetail;
use Platform\FoodAlchemist\Livewire\Verkauf\Browser as GerichtBrowser;
use Platform\FoodAlchemist\Livewire\Verkauf\DetailPanel as GerichtDetail;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeStep;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1003 Handy-Ansicht PR2 · Rezepte und Gerichte: am Handy Karten-Liste und Detail im Handbuch-Stil,
 * am Computer unverändert. Der Server erkennt das Handy am Cookie fa_handy (gesetzt von JS_MODUS).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);

    $this->rezept = $this->makeRecipe($this->rootTeam, 'Senfsauce HANDBUCH', ['work_time_min' => 25]);
    $this->makeIngredient($this->rezept, 'Dijonsenf', $this->makeGp($this->rootTeam, 'Senf: Dijon'), '120');
    FoodAlchemistRecipeStep::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->rezept->id, 'position' => 1, 'ebene' => 'produktion', 'text' => 'Senf glatt ruehren.']);

    $this->gericht = $this->makeRecipe($this->rootTeam, 'Rostbraten HANDBUCH', ['is_sales_recipe' => true]);
    FoodAlchemistRecipeStep::create(['team_id' => $this->rootTeam->id, 'recipe_id' => $this->gericht->id, 'position' => 1, 'ebene' => 'anrichten', 'text' => 'Teller vorwaermen.']);
    $this->makeStandardDarreichung($this->gericht, 19.9, ['quantity_per_unit_g' => 380]);
});

it('Rezept-Detail am Handy: Handbuch mit Kernwerten, Zutaten und Schritten statt Desktop-Detail', function () {
    Livewire::withCookie(HandyLesemodus::COOKIE, '1')
        ->test(RezeptDetail::class, ['recipeId' => $this->rezept->id])
        ->assertSeeHtml('data-fa-handbuch="rezept"')
        ->assertSee('Arbeitszeit')->assertSee('25 min')
        ->assertSee('Senf: Dijon')->assertSee('Senf glatt ruehren.')
        ->assertDontSeeHtml('data-rezept-panel')
        ->assertDontSeeHtml('data-pairing-laedt');   // kein Pairing-Nachladen am Handy
});

it('Rezept-Detail am Computer bleibt das Desktop-Detail', function () {
    Livewire::test(RezeptDetail::class, ['recipeId' => $this->rezept->id])
        ->assertSeeHtml('data-rezept-panel')
        ->assertDontSeeHtml('data-fa-handbuch');
});

it('Editor-Karteien bleiben auch am Handy die Editor-Ansicht', function () {
    Livewire::withCookie(HandyLesemodus::COOKIE, '1')
        ->test(RezeptDetail::class, ['recipeId' => $this->rezept->id, 'embedded' => true])
        ->assertDontSeeHtml('data-fa-handbuch');
});

it('Gericht-Detail am Handy: Portion, VK und Darreichungsformen mit Preis, Anrichten als Reiter', function () {
    Livewire::withCookie(HandyLesemodus::COOKIE, '1')
        ->test(GerichtDetail::class, ['recipeId' => $this->gericht->id])
        ->assertSeeHtml('data-fa-handbuch="gericht"')
        ->assertSee('380 g')
        ->assertSeeHtml('data-fa-reiter="formen"')
        ->assertSee('Teller')
        ->assertSeeHtml('data-fa-reiter="anrichten"')->assertSee('Teller vorwaermen.');
});

it('Listen am Handy: Karten ohne Anlegen-Knopf; am Computer die Tabelle', function () {
    Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test(RezeptBrowser::class)
        ->assertSeeHtml('data-fa-handy-karte="' . $this->rezept->id . '"')
        ->assertDontSeeHtml('data-rezept-anlegen')
        ->assertDontSeeHtml('data-rezept-tabelle');
    Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test(GerichtBrowser::class)
        ->assertSeeHtml('data-fa-handy-karte="' . $this->gericht->id . '"')
        ->assertDontSeeHtml("\$dispatch('vk-modal.oeffnen')");   // Anlegen-Knopf der Liste (das Editor-Fenster selbst lädt versteckt mit)

    // Laptop: JS_MODUS setzt fa_handy=0 (Livewire behält ein Test-Cookie sonst für den nächsten Aufruf)
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(RezeptBrowser::class)->assertSeeHtml('data-rezept-tabelle')->assertDontSeeHtml('data-fa-handy-liste');
    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test(GerichtBrowser::class)->assertSeeHtml('data-vk-tabelle')->assertDontSeeHtml('data-fa-handy-liste');
});

it('Server-Sperre am Handy: Auswahl und Ereignisse laufen, Ändern nicht', function () {
    request()->cookies->set(HandyLesemodus::COOKIE, '1');
    $detail = new RezeptDetail;
    $liste = new RezeptBrowser;

    expect(HandyLesemodus::abgewiesen($detail, 'statusSetzen'))->toBeTrue()
        ->and(HandyLesemodus::abgewiesen($detail, 'duplizieren'))->toBeTrue()
        ->and(HandyLesemodus::abgewiesen($detail, '__dispatch', ['recipe-selected', ['id' => 1]]))->toBeFalse()
        ->and(HandyLesemodus::abgewiesen($liste, 'waehleRezept'))->toBeFalse()
        ->and(HandyLesemodus::abgewiesen($liste, 'nextPage'))->toBeFalse()
        ->and(HandyLesemodus::abgewiesen($liste, 'bulkStatus'))->toBeTrue()
        ->and(HandyLesemodus::abgewiesen(new GerichtDetail, 'ai_klassifizieren'))->toBeTrue();

    request()->cookies->set(HandyLesemodus::COOKIE, '0');
    expect(HandyLesemodus::abgewiesen($detail, 'statusSetzen'))->toBeFalse();
});

it('Handy-Entscheidung setzt das Cookie, das der Server liest (dieselbe Entscheidung wie <html data-fa-handy>)', function () {
    expect(HandyLesemodus::JS_MODUS)->toContain("document.cookie = 'fa_handy='")
        ->toContain('location.reload()')            // Cookie passte nicht zum Aufbau → einmal neu laden
        ->toContain("sessionStorage.getItem('fa-handy-neu')")   // je Ziel nur einmal, keine Schleife
        ->toContain("addEventListener('livewire:navigated'")     // wire:navigate löscht sonst data-fa-handy
        ->not->toContain('"');                                   // steht auch in x-data
});

it('Hotfix 31.10.: kein Desktop-Schalter am Handy mehr, eine gemerkte Wahl „desktop“ wird gelöscht', function () {
    $kopf = \Illuminate\Support\Facades\Blade::render('<x-fa::detail-kopf title="T"><x-slot:aktion><span>A</span></x-slot:aktion></x-fa::detail-kopf>');
    $nav = \Illuminate\Support\Facades\Blade::render('<x-foodalchemist::handy.nav />');
    $handbuch = file_get_contents(__DIR__ . '/../../resources/views/livewire/handy/rezept-handbuch.blade.php');

    foreach ([$kopf, $nav, $handbuch] as $html) {
        expect($html)->not->toContain('Desktop-Ansicht')->not->toContain('faAnsicht');
    }
    expect(HandyLesemodus::JS_MODUS)->toContain("localStorage.getItem('fa-ansicht') === 'desktop') localStorage.removeItem('fa-ansicht')")
        ->not->toContain("a !== 'desktop'");
});

it('Hotfix 31.10.: FA-Hülle setzt data-fa-handy schon vom Server (wire:navigate übernimmt die <html>-Attribute)', function () {
    $layout = file_get_contents(__DIR__ . '/../../resources/views/layouts/standalone.blade.php');

    expect($layout)->toContain('<html lang="de" @if(\Platform\FoodAlchemist\Support\HandyLesemodus::anfrageVomHandy()) data-fa-handy @endif>');
});
