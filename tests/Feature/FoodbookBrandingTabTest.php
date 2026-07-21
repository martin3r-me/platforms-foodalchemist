<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Foodbooks\Index as FoodbooksIndex;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Phase 6: Branding/CI-Tab im Foodbook-Cockpit (Livewire-Verdrahtung). Ergänzt den
 * Service-Test FoodbookBrandingTest (dort: setBranding/storeLogo/clearLogo + dokumentDaten).
 * Hier: Tab rendert, Speichern → setBranding, Upload → storeLogo (Storage::fake),
 * Entfernen → clearLogo, Fehlerpfad (Hex-Murks → UI-Fehler statt Crash).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);

    Livewire::test(FoodbooksIndex::class)->call('neu');
    $this->fb = FoodAlchemistFoodbook::first();
});

it('rendert den Branding/CI-Tab im Cockpit', function () {
    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->assertOk()
        ->assertSee('Branding/CI')
        ->assertSee('Marken-Farbe');
});

it('Speichern setzt Marken-Farbe + Footer (leere Bandfarbe → null)', function () {
    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->set('brandingForm.brand_color', '#123456')
        ->set('brandingForm.band_color', '')
        ->set('brandingForm.footer_text', 'BHG Broich Catering')
        ->call('brandingSpeichern')
        ->assertSet('brandingGespeichert', true)
        ->assertSet('brandingFehler', null);

    $fb = $this->fb->refresh();
    expect($fb->brand_color)->toBe('#123456')
        ->and($fb->band_color)->toBeNull()
        ->and($fb->footer_text)->toBe('BHG Broich Catering');
});

it('Hex-Murks wird als UI-Fehler gezeigt statt zu crashen', function () {
    Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->set('brandingForm.brand_color', 'kein-hex')
        ->call('brandingSpeichern')
        ->assertSet('brandingGespeichert', false)
        ->assertSet('brandingFehler', fn ($v) => is_string($v) && $v !== '');
});

it('Logo-Upload legt Datei ab (Storage::fake) und Entfernen räumt sie', function () {
    Storage::fake('public');

    $comp = Livewire::test(FoodbooksIndex::class)
        ->call('waehle', $this->fb->id)
        ->set('logoUpload', UploadedFile::fake()->image('logo.png', 200, 60));

    // updatedLogoUpload greift beim Set → storeLogo schreibt auf die public-Disk.
    $logoPath = $this->fb->refresh()->logo_path;
    expect($logoPath)->not->toBeNull();
    Storage::disk('public')->assertExists($logoPath);

    $comp->call('brandingLogoEntfernen');
    expect($this->fb->refresh()->logo_path)->toBeNull();
});
