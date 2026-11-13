<?php

use Illuminate\Support\Facades\Storage;
use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Branding je Angebot (Markenfarbe, Bandfarbe, Fußzeile, Logo) gilt auch im Druck: Angebotskarte und
 * Angebotsdokument, als Druck-HTML und als PDF. Vorher stand in beiden Views fest das Standard-Violett,
 * das Branding wirkte nur in der Kundenpräsentation (Befund 13.11.2026).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->svc = app(AngebotService::class);
    $this->angebot = $this->svc->create($this->rootTeam, ['name' => 'Brand X', 'personen' => 500]);
    $this->karte = fn () => $this->get(route('foodalchemist.angebote.karte', $this->angebot->id))->assertOk()->getContent();
    $this->dokument = fn () => $this->get(route('foodalchemist.angebote.dokument', $this->angebot->id))->assertOk()->getContent();
});

it('ohne eigenes Branding bleibt es beim Standard', function () {
    expect($this->svc->brandingDaten($this->angebot->fresh()))->toMatchArray(AngebotService::BRANDING_STANDARD);
    expect(($this->karte)())->toContain('background: #6d28d9')->toContain('Erstellt mit Food Alchemist');
    expect(($this->dokument)())->toContain('border-bottom: 2px solid #6d28d9');
});

it('Markenfarbe, Bandfarbe und Fußzeile stehen in Angebotskarte und Angebotsdokument', function () {
    $this->svc->setBranding($this->rootTeam, $this->angebot->id, ['brand_color' => '#14532D', 'band_color' => '#8A9A7B', 'footer_text' => 'Brand X Catering']);

    $karte = ($this->karte)();
    expect($karte)->toContain('background: #8a9a7b')          // Band
        ->toContain('border-top: 2px solid #14532d')          // Linien in der Markenfarbe
        ->toContain('Brand X Catering')
        ->not->toContain('#6d28d9')->not->toContain('Erstellt mit Food Alchemist');

    $dokument = ($this->dokument)();
    expect($dokument)->toContain('border-bottom: 2px solid #14532d')->toContain('Brand X Catering')
        ->not->toContain('#6d28d9')->not->toContain('Erstellt mit Food Alchemist');
});

it('leere Bandfarbe übernimmt die Markenfarbe, Schrift auf dunklem Band ist weiß', function () {
    $this->svc->setBranding($this->rootTeam, $this->angebot->id, ['brand_color' => '#14532d', 'band_color' => '']);

    expect($this->svc->brandingDaten($this->angebot->fresh()))
        ->toMatchArray(['color' => '#14532d', 'band' => '#14532d', 'on_band' => '#ffffff', 'on_color' => '#ffffff']);
});

it('weiße Markenfarbe: Linien nehmen die Bandfarbe, Schrift auf hellem Band ist dunkel', function () {
    // Der gemeldete Fall: Markenfarbe #ffffff, Bandfarbe #8a9a7b. Weiße Linien auf weißem Papier wären unsichtbar.
    $this->svc->setBranding($this->rootTeam, $this->angebot->id, ['brand_color' => '#ffffff', 'band_color' => '#8a9a7b']);

    expect($this->svc->brandingDaten($this->angebot->fresh()))
        ->toMatchArray(['color' => '#8a9a7b', 'band' => '#8a9a7b', 'on_band' => '#1f2937', 'on_color' => '#1f2937']);
    expect(($this->karte)())->toContain('background: #8a9a7b; color: #1f2937')->toContain('border-top: 2px solid #8a9a7b')
        ->not->toContain('solid #ffffff');

    // Beide Farben zu hell: neutrales Grau für die Linien, das Band bleibt wie gewählt.
    $this->svc->setBranding($this->rootTeam, $this->angebot->id, ['brand_color' => '#ffffff', 'band_color' => '#fefefe']);
    expect($this->svc->brandingDaten($this->angebot->fresh()))->toMatchArray(['color' => '#374151', 'band' => '#fefefe']);
});

it('Logo steht als eingebettetes Bild im Druck (DomPDF lädt keine Adressen nach)', function () {
    Storage::fake('public');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    Storage::disk('public')->put('foodalchemist/offer-branding/test/logo.png', $png);
    $this->angebot->forceFill(['logo_path' => 'foodalchemist/offer-branding/test/logo.png'])->save();

    expect($this->svc->brandingDaten($this->angebot->fresh())['logo'])->toStartWith('data:image/png;base64,');
    expect(($this->karte)())->toContain('class="cover-logo" src="data:image/png;base64,');
    expect(($this->dokument)())->toContain('class="head-logo" src="data:image/png;base64,');
});

it('PDF-Weg der Angebotskarte und des Angebotsdokuments rendert mit Branding', function () {
    if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
        $this->markTestSkipped('DomPDF ist in dieser Umgebung nicht installiert.');
    }
    $this->svc->setBranding($this->rootTeam, $this->angebot->id, ['brand_color' => '#ffffff', 'band_color' => '#8a9a7b', 'footer_text' => 'Brand X Catering']);

    foreach (['foodalchemist.angebote.karte', 'foodalchemist.angebote.dokument'] as $route) {
        $antwort = $this->get(route($route, ['id' => $this->angebot->id, 'pdf' => 1]))->assertOk();
        expect($antwort->headers->get('content-type'))->toContain('pdf');
    }
});
