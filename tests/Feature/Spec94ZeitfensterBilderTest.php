<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Angebote\KonfiguratorPraesentation;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistKonfigurator;
use Platform\FoodAlchemist\Services\KonfiguratorService;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 94 · Bilder der Zeitfenster-Karten Tag, Abend, Tag und Abend (Dominique 08.11.2026): Upload im Reiter Branding und Präsentation
 * wie Logo/Titelbild, Snapshot `tageszeit_bilder` (nur Datei-Kennungen), Karte im Link mit Bild, ohne Bild wie bisher; MCP entfernt
 * und nennt sie; fremdes Team kommt nicht dran.
 */
beforeEach(function () {
    config(['filesystems.default' => 'public']);
    Storage::fake('public');
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(KonfiguratorService::class);
    $this->k = $this->svc->anlegen($this->rootTeam, ['name' => 'Gala', 'kunden_titel' => 'Sommerfest', 'tageszeit_aktiv' => true,
        'gueltig_bis' => now()->addMonth()->format('Y-m-d'), 'verantwortlich_user_id' => $this->user->id]);
    $c = fn (string $n, float $vk) => FoodAlchemistConcept::create(['team_id' => $this->rootTeam->id, 'name' => $n, 'kind' => 'concept', 'status' => 'active',
        'price_per_person_cache' => $vk, 'ek_per_person_cache' => 5]);
    $this->svc->konzeptHinzu($this->rootTeam, $this->k->id, ['rolle' => 'haupt', 'lunch_concept_id' => $c('Mittag', 30)->id, 'dinner_concept_id' => $c('Abend', 50)->id, 'kunden_titel' => 'Culture']);
    $this->svc->paketAnlegen($this->rootTeam, $this->k->id, ['von_pax' => 100, 'bis_pax' => 199]);
    $this->veroeffentlichen = fn () => app(PresentationService::class)->publish($this->rootTeam, PresentationService::TYPE_KONFIGURATOR, $this->k->id,
        ['expires_at' => now()->addMonth()->toIso8601String(), 'design' => 'konfigurator', 'price_mode' => 'auto']);
});

it('Upload im Editor speichert je Zeitfenster ein Bild, Entfernen räumt es', function () {
    $t = Livewire::test(KonfiguratorPraesentation::class, ['konfiguratorId' => $this->k->id])
        ->assertSeeHtml('data-kfp-zeitbilder')
        ->set('bildLunchUpload', UploadedFile::fake()->image('tag.jpg', 800, 600))
        ->set('bildDayNightUpload', UploadedFile::fake()->image('beides.jpg', 800, 600))
        ->assertSet('fehler', null)
        ->assertSeeHtml('data-kfp-zeitbild-entfernen="lunch"')
        ->assertDontSeeHtml('data-kfp-zeitbild-entfernen="dinner"');
    $k = $this->k->fresh();
    expect($k->bild_lunch_path)->not->toBeNull()->and($k->bild_lunch_context_file_id)->not->toBeNull()
        ->and($k->bild_day_night_path)->not->toBeNull()->and($k->bild_dinner_path)->toBeNull();
    Storage::disk('public')->assertExists($k->bild_lunch_path);

    $pfad = $k->bild_lunch_path;
    $t->call('zeitBildEntfernen', 'lunch')->assertSet('fehler', null);
    expect($this->k->fresh()->bild_lunch_path)->toBeNull();
    Storage::disk('public')->assertMissing($pfad);
});

it('Snapshot trägt die Bilder, die Zeitfenster-Karte zeigt ihr Bild, ohne Bild bleibt sie wie bisher', function () {
    $this->svc->bildSpeichern($this->rootTeam, $this->k->id, 'dinner', UploadedFile::fake()->image('abend.jpg', 800, 600));
    ($this->veroeffentlichen)();
    $k = FoodAlchemistKonfigurator::find($this->k->id);
    $bilder = $k->presentation_snapshot_json['content']['tageszeit_bilder'];
    expect($bilder['dinner']['path'])->toBe($k->bild_dinner_path)->and($bilder['lunch'])->toBeNull()->and($bilder['day_night'])->toBeNull();

    auth()->logout();
    $html = $this->get('/p/konfigurator/' . $k->presentation_token)->assertOk()->getContent();
    $karte = fn (string $t) => preg_match('#<button type="button" class="pt-konfig-karte ([^"]*)" data-konfig-tageszeit="' . $t . '"[^>]*>\s*(<img[^>]*>)?#', $html, $m) ? $m : null;
    expect($karte('dinner')[1])->toContain('pt-konfig-karte--bild')
        ->and($karte('dinner')[2] ?? '')->toContain(basename($k->bild_dinner_path))->toContain('loading="lazy"')
        ->and($karte('lunch')[1])->toContain('pt-konfig-karte--ohne-bild')
        ->and($karte('lunch')[2] ?? '')->toBe('');
});

it('Design-Schalter show_bild=false blendet die Zeitfenster-Bilder aus', function () {
    $this->svc->bildSpeichern($this->rootTeam, $this->k->id, 'lunch', UploadedFile::fake()->image('tag.jpg', 800, 600));
    $html = view('foodalchemist::presentation.blocks.konfig_tageszeit', [
        'content' => ['tageszeit_aktiv' => true, 'kombi_rabatt_pct' => 0, 'konzepte' => [],
            'tageszeit_bilder' => ['lunch' => ['context_file_id' => null, 'path' => $this->k->fresh()->bild_lunch_path], 'dinner' => null, 'day_night' => null]],
        'style' => ['show_bild' => false],
    ])->render();
    expect($html)->not->toContain('<img')->and($html)->not->toContain('pt-konfig-karte--bild');
});

it('MCP: BRANDING nennt die Bild-Pfade und entfernt ein Zeitfenster-Bild; fremdes Team kommt nicht dran', function () {
    $this->svc->bildSpeichern($this->rootTeam, $this->k->id, 'day_night', UploadedFile::fake()->image('beides.jpg', 800, 600));
    $tool = app(ToolRegistry::class)->get('foodalchemist.konfiguratoren.BRANDING');

    $fremd = $tool->execute(['id' => $this->k->id, 'bild_entfernen' => 'day_night'], new ToolContext($this->makeUser($this->childB), $this->childB));
    expect($fremd->success)->toBeFalse()->and($this->k->fresh()->bild_day_night_path)->not->toBeNull();
    expect(fn () => $this->svc->bildSpeichern($this->childB, $this->k->id, 'lunch', UploadedFile::fake()->image('x.jpg', 10, 10)))->toThrow(Exception::class, 'Besitzer-Team');

    $res = $tool->execute(['id' => $this->k->id, 'bild_entfernen' => 'day_night'], new ToolContext($this->user, $this->rootTeam));
    expect($res->success)->toBeTrue()->and($res->data['branding'])->toHaveKeys(['bild_lunch_path', 'bild_dinner_path', 'bild_day_night_path'])
        ->and($res->data['branding']['bild_day_night_path'])->toBeNull()
        ->and($this->k->fresh()->bild_day_night_path)->toBeNull();
});
