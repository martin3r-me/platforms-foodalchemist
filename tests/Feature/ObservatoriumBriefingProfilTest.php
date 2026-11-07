<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Observatorium\Index;
use Platform\FoodAlchemist\Models\FoodAlchemistObservatoriumBriefingProfil as Profil;
use Platform\FoodAlchemist\Models\FoodAlchemistTrendEingang;
use Platform\FoodAlchemist\Services\ObservatoriumBriefingService;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\TrendUrl;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * „Mein Briefing“ mit gespeicherter Einstellung (Dominique 07.11.2026): je Person Branchen (ODER), Region, Zeitraum, Themen (ODER).
 * Ohne Einstellung bleibt das Briefing die Kurzfassung der aktuellen Filter. Testdaten „TEST-BP“, alle Werte erfunden.
 */
function bpEintrag(int $teamId, string $titel, array $branchen, array $extra = []): FoodAlchemistTrendEingang
{
    $url = 'https://news.beispiel.test/bp-'.uniqid();

    return FoodAlchemistTrendEingang::create(array_merge(['team_id' => $teamId, 'url' => $url, 'url_hash' => TrendUrl::hash($url),
        'titel' => $titel, 'teaser' => 'TEST-BP Teaser', 'status' => 'gebuendelt', 'eingereicht_von' => 'mcp', 'herausgeber' => 'TEST-Blatt',
        'veroeffentlicht_am' => now()->subDays(2), 'observatorium' => ['verwendungen' => ['news'], 'branchen' => $branchen, 'region' => 'DE', 'herkunft' => 'ki']], $extra));
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL TEST-BP', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    MasterTeam::flush();
    $this->mandant = $this->rootTeam;
    $this->anna = $this->makeUser($this->mandant, 'Anna TEST-BP', 'viewer');
    $this->ben = $this->makeUser($this->mandant, 'Ben TEST-BP', 'viewer');
    $this->svc = app(ObservatoriumBriefingService::class);
    $this->catering = bpEintrag($this->master->id, 'TEST-BP Catering mit Proteinbowls', ['catering']);
    $this->event = bpEintrag($this->master->id, 'TEST-BP Messe-Event setzt auf alkoholfrei', ['event']);
    $this->kita = bpEintrag($this->master->id, 'TEST-BP Kita-Speiseplan mit Linsen', ['kita']);
    $this->alt = bpEintrag($this->master->id, 'TEST-BP Alter Catering-Bericht', ['catering'], ['veroeffentlicht_am' => now()->subDays(20)]);
    $this->ch = bpEintrag($this->master->id, 'TEST-BP Catering in Zürich', ['catering'], ['observatorium' => ['verwendungen' => ['news'], 'branchen' => ['catering'], 'region' => 'CH', 'herkunft' => 'ki']]);
});

it('Service: speichern je Person, prüft Werte, Themen bereinigt; Filter wirkt als ODER über Branchen und Themen', function () {
    $p = $this->svc->speichern($this->mandant, $this->anna->id, ['branchen' => ['catering', 'event'], 'region' => 'DE', 'tage' => '7', 'themen' => ' Protein, protein ,alkoholfrei,']);
    expect($p->themen)->toBe(['Protein', 'alkoholfrei'])
        ->and(ObservatoriumBriefingService::zusammenfassung($p))->toBe('Catering, Events & Veranstaltungen · Deutschland · Letzte 7 Tage · Themen: Protein, alkoholfrei')
        ->and($this->svc->profil($this->mandant, $this->ben->id))->toBeNull()
        ->and(fn () => $this->svc->speichern($this->mandant, $this->anna->id, ['branchen' => ['mars']]))->toThrow(\RuntimeException::class, 'Unbekannte Branche')
        ->and(fn () => $this->svc->speichern($this->mandant, $this->anna->id, ['region' => 'FR']))->toThrow(\RuntimeException::class, 'Region')
        ->and(fn () => $this->svc->speichern($this->mandant, $this->anna->id, ['tage' => '365']))->toThrow(\RuntimeException::class, 'Zeitraum')
        ->and(fn () => $this->svc->speichern($this->mandant, $this->anna->id, ['themen' => implode(',', range(1, 11))]))->toThrow(\RuntimeException::class, 'Höchstens');

    $this->actingAs($this->anna);
    $ids = fn (array $f) => app(\Platform\FoodAlchemist\Services\ObservatoriumService::class)->gruppiert($this->mandant, $f)->pluck('id')->all();
    // Branchen ODER + Region DE + 7 Tage + Themen ODER: Catering (Protein) und Event (alkoholfrei); nicht Kita, nicht alt, nicht CH
    expect($ids(ObservatoriumBriefingService::filter($p->refresh())))->toEqualCanonicalizing([$this->catering->id, $this->event->id]);
    // Nur Branchen, ohne Themen: beide Catering/Event in DE der letzten 7 Tage
    $nurBranchen = $this->svc->speichern($this->mandant, $this->anna->id, ['branchen' => ['catering'], 'region' => '', 'tage' => '30']);
    expect($ids(ObservatoriumBriefingService::filter($nurBranchen)))->toEqualCanonicalizing([$this->catering->id, $this->alt->id, $this->ch->id])
        ->and(Profil::where('user_id', $this->anna->id)->count())->toBe(1);
});

it('Oberfläche: ohne Einstellung Kurzfassung der Filter, mit Einstellung immer die eigene Auswahl; nur für die eigene Person', function () {
    $this->actingAs($this->anna);
    Livewire::test(Index::class)->call('ansichtSetzen', 'briefing')
        ->assertSee('Briefing einstellen')->assertSee('Aus deiner Recherche')
        ->call('profilBearbeiten')->assertSet('profilOffen', true)->assertSeeHtml('data-briefing-profil-form')
        ->set('profil.branchen', ['kita'])->set('profil.tage', '7')->set('profil.themen', 'Linsen')
        ->call('profilSpeichern')
        ->assertSet('profilOffen', false)
        ->assertSee('Dein Briefing ist eingestellt.')
        ->assertSee('Kita & Krippe · Alle Regionen · Letzte 7 Tage · Themen: Linsen')
        ->assertSee('Deine Auswahl')
        ->assertSee('TEST-BP Kita-Speiseplan mit Linsen')
        ->assertDontSee('TEST-BP Catering mit Proteinbowls')
        ->assertDontSeeHtml('aria-label="Branche filtern"')   // Filterleiste weg, das Profil gilt
        ->set('branche', 'catering')->assertSee('TEST-BP Kita-Speiseplan mit Linsen');   // offener Filter ändert das Briefing nicht

    // Ben hat keine Einstellung — sieht Annas Auswahl nicht
    $this->actingAs($this->ben);
    Livewire::test(Index::class)->call('ansichtSetzen', 'briefing')->assertSee('Briefing einstellen')->assertDontSee('Deine Auswahl');

    // Leer: eigener Hinweis; Löschen bringt die Filter zurück
    $this->actingAs($this->anna);
    $this->svc->speichern($this->mandant, $this->anna->id, ['branchen' => ['hotel'], 'tage' => '7']);
    Livewire::test(Index::class)->call('ansichtSetzen', 'briefing')
        ->assertSeeHtml('data-briefing-leer')
        ->call('profilBearbeiten')->call('profilLoeschen')
        ->assertSee('Das Briefing zeigt wieder deine aktuellen Filter.')
        ->assertSeeHtml('aria-label="Branche filtern"');
    expect(Profil::where('user_id', $this->anna->id)->exists())->toBeFalse();
});

it('MCP: observatorium_briefing.PUT/GET für die eigene Person, Fehler als VALIDATION_ERROR, löschen', function () {
    $reg = app(ToolRegistry::class);
    $ctx = new ToolContext($this->anna, $this->mandant);
    expect($reg->get('foodalchemist.observatorium_briefing.GET')->execute([], $ctx)->data)->toBe(['einstellung' => null, 'meldungen' => []]);
    $put = $reg->get('foodalchemist.observatorium_briefing.PUT')->execute(['branchen' => ['event'], 'region' => 'DE', 'tage' => '7', 'themen' => ['alkoholfrei']], $ctx);
    expect($put->success)->toBeTrue()->and($put->data['einstellung']['kurz'])->toBe('Events & Veranstaltungen · Deutschland · Letzte 7 Tage · Themen: alkoholfrei');
    $get = $reg->get('foodalchemist.observatorium_briefing.GET')->execute(['limit' => 3], $ctx);
    expect(array_column($get->data['meldungen'], 'eingang_id'))->toBe([$this->event->id])
        ->and($reg->get('foodalchemist.observatorium_briefing.GET')->execute([], new ToolContext($this->ben, $this->mandant))->data['einstellung'])->toBeNull()
        ->and($reg->get('foodalchemist.observatorium_briefing.PUT')->execute(['branchen' => ['mars']], $ctx)->errorCode)->toBe('VALIDATION_ERROR')
        ->and($reg->get('foodalchemist.observatorium_briefing.PUT')->execute(['loeschen' => true], $ctx)->data)->toBe(['einstellung' => null])
        ->and(Profil::count())->toBe(0);
});
