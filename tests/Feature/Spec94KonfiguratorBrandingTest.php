<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Angebote\KonfiguratorPraesentation;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator;
use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 94 · Reiter Branding und Präsentation wie im Foodbook: „Gültig bis“ ist Pflicht zum Veröffentlichen, Logo und Titelbild
 * über dieselbe Upload-Mechanik (x-foodalchemist::branding-bilder), der Snapshot trägt das Branding des Konfigurators.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    $this->k = app(KonfiguratorService::class)->anlegen($this->rootTeam, ['name' => 'Gala']);
});

it('Veröffentlichen ohne „Gültig bis“ wird abgewiesen, mit Datum läuft der Link bis zu diesem Tag', function () {
    Livewire::test(KonfiguratorPraesentation::class, ['konfiguratorId' => $this->k->id])
        ->assertSee('Präsentation für den Kunden')
        ->assertSeeHtml('data-kfp-gueltig')
        ->call('veroeffentlichen')
        ->assertSet('fehler', 'Zum Veröffentlichen ein Datum bei „Gültig bis“ setzen.');
    expect($this->k->fresh()->presentation_enabled)->toBeFalse();

    $bis = now()->addMonth()->format('Y-m-d');
    Livewire::test(KonfiguratorPraesentation::class, ['konfiguratorId' => $this->k->id])
        ->set('gueltigBis', $bis)
        ->call('veroeffentlichen')
        ->assertSet('fehler', null)
        ->assertSeeHtml('data-kfp-url');
    $k = $this->k->fresh();
    expect($k->gueltig_bis->format('Y-m-d'))->toBe($bis)
        ->and($k->presentation_expires_at->format('Y-m-d'))->toBe($bis);

    // Neu geladen steht das Datum wieder im Feld
    Livewire::test(KonfiguratorPraesentation::class, ['konfiguratorId' => $this->k->id])->assertSet('gueltigBis', $bis);
});

it('Logo- und Titelbild-Upload legen die Datei ab, Entfernen räumt sie; der Snapshot trägt das Branding', function () {
    config(['filesystems.default' => 'public']);
    Storage::fake('public');

    $t = Livewire::test(KonfiguratorPraesentation::class, ['konfiguratorId' => $this->k->id])
        ->set('logoUpload', UploadedFile::fake()->image('logo.png', 200, 60))
        ->set('coverUpload', UploadedFile::fake()->image('titel.jpg', 800, 400))
        ->assertSet('fehler', null)
        ->assertSeeHtml('data-logo-entfernen')
        ->assertSeeHtml('data-cover-entfernen');
    $k = $this->k->fresh();
    expect($k->logo_path)->not->toBeNull()->and($k->logo_context_file_id)->not->toBeNull()
        ->and($k->cover_image_path)->not->toBeNull();
    Storage::disk('public')->assertExists($k->logo_path);

    $t->set('gueltigBis', now()->addMonth()->format('Y-m-d'))->call('veroeffentlichen')->assertSet('fehler', null);
    $snap = FoodAlchemistKonfigurator::find($this->k->id)->presentation_snapshot_json;
    expect($snap['branding']['logo']['path'])->toBe($k->logo_path)
        ->and($snap['branding']['cover']['path'])->toBe($k->cover_image_path);

    $logo = $k->logo_path;
    $t->call('brandingLogoEntfernen');
    expect($this->k->fresh()->logo_path)->toBeNull();
    Storage::disk('public')->assertMissing($logo);
});

it('Ein übernommenes Logo wird beim Ersetzen am Konfigurator nicht beim Foodbook gelöscht', function () {
    config(['filesystems.default' => 'public']);
    Storage::fake('public');
    $fb = FoodAlchemistFoodbook::create(['team_id' => $this->rootTeam->id, 'label' => 'Hochzeit', 'code' => 'FB-1']);
    $pfad = app(\Platform\FoodAlchemist\Services\FoodbookService::class)->storeLogo($this->rootTeam, $fb->id, UploadedFile::fake()->image('fb.png', 200, 60));

    $svc = app(KonfiguratorService::class);
    $svc->brandingUebernehmen($this->rootTeam, $this->k->id, 'foodbook', $fb->id);
    expect($this->k->fresh()->logo_path)->toBe($pfad);

    $svc->bildSpeichern($this->rootTeam, $this->k->id, 'logo', UploadedFile::fake()->image('neu.png', 200, 60));
    expect($this->k->fresh()->logo_path)->not->toBe($pfad);
    Storage::disk('public')->assertExists($pfad);   // gehört weiter dem Foodbook
});

it('Ohne eigenes Logo gilt das Logo des Betriebs: Vorschau „aus Betrieb“, Snapshot friert es ein; eigenes Logo gewinnt', function () {
    $o = \Platform\FoodAlchemist\Models\FoodAlchemistOutlet::create(['team_id' => $this->rootTeam->id, 'name' => 'Rheingedeck', 'code' => 'RG',
        'logo_path' => 'foodalchemist/betrieb/rg.png']);

    $t = Livewire::test(KonfiguratorPraesentation::class, ['konfiguratorId' => $this->k->id])
        ->assertDontSeeHtml('data-kfp-logo-aus-betrieb')
        ->set('branding.outlet_id', (string) $o->id)
        ->call('brandingSpeichern')
        ->assertSet('fehler', null)
        ->assertSeeHtml('data-kfp-logo-aus-betrieb')
        ->assertSee('Logo aus Betrieb')
        ->assertDontSeeHtml('data-brand-color');   // keine Farbwähler mehr: Farben aus dem Design
    expect($this->k->fresh()->outlet_id)->toBe($o->id);

    $t->set('gueltigBis', now()->addMonth()->format('Y-m-d'))->call('veroeffentlichen')->assertSet('fehler', null);
    expect(FoodAlchemistKonfigurator::find($this->k->id)->presentation_snapshot_json['branding']['logo']['path'])->toBe('foodalchemist/betrieb/rg.png');

    // Eigenes Logo gewinnt vor dem Betrieb
    $this->k->update(['logo_path' => 'foodalchemist/branding/eigen.png']);
    expect($this->k->fresh()->wirksamesLogo())->toMatchArray(['path' => 'foodalchemist/branding/eigen.png', 'aus_betrieb' => false]);

    // Fremder Betrieb wird abgewiesen
    expect(fn () => app(KonfiguratorService::class)->branding($this->rootTeam, $this->k->id, ['outlet_id' => 999999]))
        ->toThrow(InvalidArgumentException::class, 'Betrieb');
});
