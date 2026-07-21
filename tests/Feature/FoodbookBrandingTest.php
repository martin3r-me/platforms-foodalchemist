<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Foodbook-PDF-Redesign: pro-Foodbook-Branding-Unterbau. Service-API (setBranding/
 * storeLogo/clearLogo) + branding-Key in dokumentDaten (DomPDF-taugliche base64-Bilder).
 * Owner-Guard (D1) greift. UI-Tab (Cockpit) = separate Session, hier nicht getestet.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    Storage::fake('public');
    $this->svc = app(FoodbookService::class);
});

it('dokumentDaten liefert branding-Defaults (Farbe = Violett, band = Farbe, Bilder null)', function () {
    $fb = $this->svc->create($this->rootTeam, ['label' => 'Angebot Test', 'personen' => 50]);

    $data = $this->svc->dokumentDaten($this->rootTeam, $fb, false);

    expect($data)->toHaveKey('branding');
    expect($data['branding']['color'])->toBe('#6d28d9');
    expect($data['branding']['band'])->toBe('#6d28d9');   // band leer → aus color abgeleitet
    expect($data['branding']['logo'])->toBeNull();
    expect($data['branding']['cover'])->toBeNull();
    expect($data['branding']['footer'])->toBeNull();
});

it('setBranding setzt Marken-Farbe + Footer, validiert Hex, leert band mit ""', function () {
    $fb = $this->svc->create($this->rootTeam, ['label' => 'Marke']);

    $this->svc->setBranding($this->rootTeam, $fb->id, [
        'brand_color' => '#C1121F', 'footer_text' => 'BHG BROICH CATERING.COM', 'band_color' => '',
    ]);

    $data = $this->svc->dokumentDaten($this->rootTeam, $fb->refresh(), false);
    expect($data['branding']['color'])->toBe('#c1121f')          // normalisiert (lowercase)
        ->and($data['branding']['band'])->toBe('#c1121f')        // band '' → aus color
        ->and($data['branding']['footer'])->toBe('BHG BROICH CATERING.COM');

    // Ungültiger Hex → typisierter Fehler
    expect(fn () => $this->svc->setBranding($this->rootTeam, $fb->id, ['brand_color' => 'rot']))
        ->toThrow(\RuntimeException::class);
});

it('storeLogo speichert auf public-Disk + brandingDaten liefert base64-Data-URI; clearLogo räumt', function () {
    $fb = $this->svc->create($this->rootTeam, ['label' => 'Logo-FB']);
    $file = UploadedFile::fake()->image('logo.png', 40, 40);

    $pfad = $this->svc->storeLogo($this->rootTeam, $fb->id, $file);

    expect($pfad)->toStartWith("foodalchemist/branding/{$fb->id}/");
    Storage::disk('public')->assertExists($pfad);
    expect($fb->refresh()->logo_path)->toBe($pfad);

    // DomPDF-tauglich: als base64 im Blade-Datenpaket (nicht http-URL)
    $data = $this->svc->dokumentDaten($this->rootTeam, $fb->refresh(), false);
    expect($data['branding']['logo'])->toStartWith('data:image/');

    // Aufräumen: Datei weg + Spalte null
    $this->svc->clearLogo($this->rootTeam, $fb->id);
    Storage::disk('public')->assertMissing($pfad);
    expect($fb->refresh()->logo_path)->toBeNull();
});

it('Owner-Guard (D1): geerbtes Foodbook lässt sich nicht branden', function () {
    // Foodbook gehört dem Root; childA sieht es geerbt, darf es aber nicht pflegen.
    $fb = $this->svc->create($this->rootTeam, ['label' => 'Master-FB']);
    $childUser = $this->makeUser($this->childA);
    $this->actingAs($childUser);

    expect(fn () => $this->svc->setBranding($this->childA, $fb->id, ['brand_color' => '#000000']))
        ->toThrow(\RuntimeException::class);
});
