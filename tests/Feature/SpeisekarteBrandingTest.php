<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Speisekarte\Index as SpeisekarteIndex;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Speisekarte Stufe C — Branding (Farben/Footer/Logo/Cover) + Data-URI fürs Dokument. */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->karten = app(SpeisekarteService::class);
});

it('Stufe C: Farben + Footer setzen; ungültiges Hex wirft', function () {
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);
    $this->karten->setBranding($this->rootTeam, $karte->id, [
        'brand_color' => '#112233', 'band_color' => '', 'footer_text' => 'Restaurant Adler',
    ]);
    $karte->refresh();
    expect($karte->brand_color)->toBe('#112233')
        ->and($karte->band_color)->toBeNull()          // leer → null (Blade leitet ab)
        ->and($karte->footer_text)->toBe('Restaurant Adler');

    expect(fn () => $this->karten->setBranding($this->rootTeam, $karte->id, ['brand_color' => 'blau']))
        ->toThrow(\RuntimeException::class);
});

it('Stufe C: Logo-Upload → brandingDaten liefert base64-Data-URI', function () {
    config(['filesystems.default' => 'public']);
    Storage::fake('public');
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);

    $this->karten->storeLogo($this->rootTeam, $karte->id, UploadedFile::fake()->image('logo.png', 200, 80));
    expect($karte->refresh()->logo_context_file_id)->not->toBeNull();
    $branding = $this->karten->brandingDaten($karte->refresh());
    expect($branding['logo'])->toStartWith('data:image/')
        ->and($branding['logo'])->toContain('base64,');

    $this->karten->clearLogo($this->rootTeam, $karte->id);
    expect($this->karten->brandingDaten($karte->refresh())['logo'])->toBeNull()
        ->and($karte->logo_context_file_id)->toBeNull();
});

it('Stufe C: Branding-Tab im Editor speichert Farbe', function () {
    config(['filesystems.default' => 'public']);
    Storage::fake('public');
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $karte = $this->karten->create($this->rootTeam, ['name' => 'K']);

    Livewire::test(SpeisekarteIndex::class)
        ->call('waehle', $karte->id)
        ->set('brandColor', '#0055aa')
        ->set('footerText', 'Fußzeile X')
        ->call('brandingSpeichern')
        ->assertOk();

    expect($karte->refresh()->brand_color)->toBe('#0055aa')
        ->and($karte->footer_text)->toBe('Fußzeile X');
});
