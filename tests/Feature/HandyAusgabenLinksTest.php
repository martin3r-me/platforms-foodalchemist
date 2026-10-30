<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index as Foodbooks;
use Platform\FoodAlchemist\Livewire\Speisekarte\Index as Speisekarten;
use Platform\FoodAlchemist\Livewire\Speiseplan\Index as Speiseplaene;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Services\ReportExportService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Support\HandyLesemodus;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #1003 Handy-Ansicht PR6 · Foodbooks, Speisekarten, Speisepläne: am Handy Karten mit Suche, Antippen öffnet die
 * vorhandene Live-Vorschau (Kundensicht); alles Schreibende ist serverseitig gesperrt. Am Computer unverändert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->foodbook = app(FoodbookService::class)->create($this->rootTeam, ['label' => 'Sommerfest HANDY']);
    $this->karte = app(SpeisekarteService::class)->create($this->rootTeam, ['name' => 'Abendkarte HANDY']);
    $this->plan = app(SpeiseplanService::class)->create($this->rootTeam, ['name' => 'Kantine HANDY']);
});

function handyPr6(string $klasse)
{
    return Livewire::withCookie(HandyLesemodus::COOKIE, '1')->test($klasse);
}

dataset('ausgaben', [
    'Foodbooks' => [Foodbooks::class, 'foodbook', 'Sommerfest HANDY', 'foodalchemist.foodbooks.praesentation', 'Foodbook anlegen'],
    'Speisekarten' => [Speisekarten::class, 'karte', 'Abendkarte HANDY', 'foodalchemist.speisekarte.praesentation', 'Neue Karte'],
    'Speisepläne' => [Speiseplaene::class, 'plan', 'Kantine HANDY', 'foodalchemist.speiseplan.praesentation', 'Neuer Plan'],
]);

it('am Handy: Karten mit Link auf die Live-Vorschau, Suche filtert, kein Anlegen; am Computer unverändert', function (string $klasse, string $obj, string $name, string $route, string $anlegen) {
    $id = $this->{$obj}->id;
    handyPr6($klasse)
        ->assertSeeHtml('data-fa-handy-ausgaben')->assertSee($name)
        ->assertSeeHtml('href="' . route($route, $id) . '"')
        ->assertSeeHtml('data-fa-handy-karte="' . $id . '"')
        ->assertDontSee($anlegen)
        ->set('search', 'gibtsnicht')->assertDontSeeHtml('data-fa-handy-karte="' . $id . '"');

    Livewire::withCookie(HandyLesemodus::COOKIE, '0')->test($klasse)
        ->assertSee($anlegen)->assertDontSeeHtml('data-fa-handy-ausgaben');
})->with('ausgaben');

it('die Live-Vorschau hinter der Karte öffnet (Kundensicht, angemeldet)', function (string $klasse, string $obj, string $name, string $route) {
    $this->withCookie(HandyLesemodus::COOKIE, '1')->get(route($route, $this->{$obj}->id))->assertOk();
})->with('ausgaben');

it('Server-Sperre am Handy: nur Blättern; Anlegen, Speichern, Duplizieren, Löschen, Veröffentlichen, KI und Upload nein', function () {
    request()->cookies->set(HandyLesemodus::COOKIE, '1');
    foreach ([Foodbooks::class, Speisekarten::class, Speiseplaene::class] as $klasse) {
        foreach (['nextPage', 'previousPage', 'gotoPage'] as $m) {
            expect(HandyLesemodus::abgewiesen(new $klasse, $m))->toBeFalse($klasse . '::' . $m);
        }
        foreach (['neu', 'waehle', 'duplizieren', '_startUpload'] as $m) {
            expect(HandyLesemodus::abgewiesen(new $klasse, $m))->toBeTrue($klasse . '::' . $m);
        }
    }
    foreach (['speichern', 'loeschen', 'veroeffentlichen', 'brandingSpeichern', 'kapitelNeu', 'kiKapitelText', 'vollKaskadeStarten'] as $m) {
        expect(HandyLesemodus::abgewiesen(new Foodbooks, $m))->toBeTrue('Foodbooks::' . $m);
    }
    foreach (['speichern', 'loeschen', 'veroeffentlichen', 'rubrikNeu', 'positionAusGericht', 'kiWording'] as $m) {
        expect(HandyLesemodus::abgewiesen(new Speisekarten, $m))->toBeTrue('Speisekarten::' . $m);
    }
    expect(HandyLesemodus::abgewiesen(new Speiseplaene, 'bearbeiten'))->toBeTrue();
    request()->cookies->set(HandyLesemodus::COOKIE, '0');
});

it('Server-Sperre wirkt: Anlegen und Duplizieren vom Handy legen nichts an', function () {
    $vorher = [FoodAlchemistFoodbook::count(), FoodAlchemistSpeisekarte::count(), FoodAlchemistSpeiseplan::count()];
    handyPr6(Foodbooks::class)->call('neu');
    handyPr6(Speisekarten::class)->call('neu');
    handyPr6(Speiseplaene::class)->set('selectedId', $this->plan->id)->call('duplizieren');
    expect([FoodAlchemistFoodbook::count(), FoodAlchemistSpeisekarte::count(), FoodAlchemistSpeiseplan::count()])->toBe($vorher);
});

it('Team-Sicht am Handy: ein fremdes Team sieht keine fremden Ausgaben', function (string $klasse, string $obj, string $name) {
    $this->actingAs($this->makeUser(Team::create(['name' => 'Fremder Kunde', 'user_id' => 1, 'personal_team' => false]), 'Fremd'));
    handyPr6($klasse)->assertSeeHtml('data-fa-handy-ausgaben')->assertDontSee($name)
        ->assertDontSeeHtml('data-fa-handy-karte="' . $this->{$obj}->id . '"');
})->with('ausgaben');

it('„Intern“ (Dominique 30.10.): Foodbook und Speisekarte verlinken den Report im Profil Kalkulation, der Speiseplan nicht', function () {
    handyPr6(Foodbooks::class)->assertSeeHtml('data-fa-handy-intern="' . $this->foodbook->id . '"')
        ->assertSeeHtml('href="' . route('foodalchemist.foodbooks.report', ['id' => $this->foodbook->id, 'profil' => 'kalkulation']) . '"');
    handyPr6(Speisekarten::class)->assertSeeHtml('data-fa-handy-intern="' . $this->karte->id . '"')
        ->assertSeeHtml('href="' . route('foodalchemist.speisekarte.report', ['id' => $this->karte->id, 'profil' => 'kalkulation']) . '"');
    handyPr6(Speiseplaene::class)->assertDontSeeHtml('data-fa-handy-intern');

    $opt = app(ReportExportService::class)->optionen(['profil' => 'kalkulation'], 'foodbook');
    expect($opt['preise'])->toBeTrue()->and($opt['intern'])->toBeTrue();
});

it('„Intern“ öffnet den Report mit Preisen; ein fremdes Team kommt nicht hinein', function () {
    $this->get(route('foodalchemist.foodbooks.report', ['id' => $this->foodbook->id, 'profil' => 'kalkulation']))
        ->assertOk()->assertSee('Profil: Kalkulation')->assertSee('name="viewport"', false);
    $this->get(route('foodalchemist.speisekarte.report', ['id' => $this->karte->id, 'profil' => 'kalkulation']))->assertOk();

    $this->actingAs($this->makeUser(Team::create(['name' => 'Fremd Report', 'user_id' => 1, 'personal_team' => false]), 'Fremd'));
    expect($this->get(route('foodalchemist.foodbooks.report', ['id' => $this->foodbook->id, 'profil' => 'kalkulation']))->status())->toBeIn([403, 404]);
});

it('Report am Handy: Bildschirm-Regel und viewport nur in der HTML-Ausgabe, der PDF-Pfad (DomPDF) bleibt unverändert', function () {
    $daten = app(ReportExportService::class)->foodbookDaten($this->rootTeam, $this->foodbook->id,
        app(ReportExportService::class)->optionen(['profil' => 'kalkulation'], 'foodbook'), []);
    $html = view('foodalchemist::dokumente.report', $daten + ['istPdf' => false])->render();
    $pdf = view('foodalchemist::dokumente.report', $daten + ['istPdf' => true])->render();

    expect($html)->toContain('@media screen and (max-width: 640px)')->toContain('name="viewport"')
        ->and($pdf)->not->toContain('max-width: 640px')->not->toContain('name="viewport"')
        // Der PDF-Modus behält seine eigene Blattbreite (DomPDF-Leitplanken).
        ->and(preg_replace('/\s+/', ' ', $pdf))->toContain('.doc { max-width: none;');
});

it('Speiseplan-Aushang: am Handy (≤ 640 px) Tagesliste statt breiter Tabelle; Tabelle bleibt für Computer und Druck', function () {
    $grid = ['tage' => [['key' => '2026-10-26', 'label' => 'Mo 26.10.'], ['key' => '2026-10-27', 'label' => 'Di 27.10.']],
        'lines' => [['name' => 'Menü 1', 'color' => '#c33', 'cells' => ['2026-10-26' => [['label' => 'Senfrostbraten', 'codes' => ['A'], 'price' => '9,50 €']]]]]];
    $html = view('foodalchemist::presentation.blocks.grid', ['content' => ['grid' => $grid], 'style' => []])->render();

    expect($html)->toContain('class="pt-grid"')->toContain('class="pt-grid-rahmen"')->toContain('class="pt-grid-tage"')
        ->toContain('<h3 class="pt-grid-tag-titel">Mo 26.10.</h3>')->toContain('Senfrostbraten')
        ->not->toContain('<h3 class="pt-grid-tag-titel">Di 27.10.</h3>');   // leerer Tag fällt in der Tagesliste weg

    $css = file_get_contents(dirname(__DIR__, 2) . '/resources/views/layouts/presentation.blade.php');
    expect($css)->toContain('.pt-grid-tage { display: none; }')
        ->toMatch('/@media screen and \(max-width: 640px\) \{\s*\.pt-grid-rahmen \{ display: none; \}/');
});

it('Rezept-PDF-Link öffnet inline (am Handy im PDF-Betrachter) mit Dateinamen, Umlaute kodiert; ohne Signatur kein Zugriff', function () {
    $token = str_repeat('ab', 32);
    Cache::put('foodalchemist:mcp-recipe-pdf:' . $token, ['content_base64' => base64_encode("%PDF-1.4\n%%EOF"), 'filename' => 'Rösti mit Kräuterquark.pdf'], 600);
    $url = URL::temporarySignedRoute('foodalchemist.recipes.pdf_download', now()->addMinutes(10), ['token' => $token]);

    $antwort = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $kopf = $antwort->headers->get('Content-Disposition');
    expect($kopf)->toStartWith('inline;')->toContain('filename="Rosti mit Krauterquark.pdf"')
        ->toContain("filename*=utf-8''R%C3%B6sti%20mit%20Kr%C3%A4uterquark.pdf");

    $this->get(route('foodalchemist.recipes.pdf_download', ['token' => $token]))->assertForbidden();
});
