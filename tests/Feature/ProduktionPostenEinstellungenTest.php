<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Settings\Posten;
use Platform\FoodAlchemist\Models\FoodAlchemistProductionStation;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Landebahn mit Weg: "Posten für Gerichte" (Team-Vorgabe) und das Merkmal "Sammelposten (Pass)" sind in der Posten-Verwaltung pflegbar
 * und per MCP les-/setzbar (production_stations.GET/PUT, team_settings.PUT).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->pass = FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id, 'slug' => 'pass', 'name' => 'Pass']);
    $this->saucier = FoodAlchemistProductionStation::create(['team_id' => $this->rootTeam->id, 'slug' => 'saucier', 'name' => 'Saucier']);
    $fremd = Team::create(['name' => 'Fremd', 'user_id' => 1, 'personal_team' => false]);
    $this->fremderPosten = FoodAlchemistProductionStation::create(['team_id' => $fremd->id, 'slug' => 'fremd', 'name' => 'Fremd']);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->reg = app(ToolRegistry::class);
});

it('★ Posten-Verwaltung: „Posten für Gerichte" ist sichtbar, speicherbar und wirkt', function () {
    Livewire::test(Posten::class)
        ->assertSeeHtml('data-posten-gerichte')
        ->assertSeeHtml('data-posten-gerichte-select')
        ->set('gerichteStationId', (string) $this->pass->id)
        ->call('gerichtePostenSpeichern')
        ->assertSet('fehler', null)
        ->assertSet('meldung', fn ($m) => str_contains((string) $m, 'gespeichert'));

    expect(app(TeamSettingsService::class)->gerichtePostenId($this->rootTeam))->toBe((int) $this->pass->id);

    Livewire::test(Posten::class)->assertSet('gerichteStationId', (string) $this->pass->id);   // beim Öffnen vorbelegt

    Livewire::test(Posten::class)->set('gerichteStationId', '')->call('gerichtePostenSpeichern');
    expect(app(TeamSettingsService::class)->gerichtePostenId($this->rootTeam))->toBeNull();      // leer löscht die Vorgabe
});

it('Posten-Verwaltung: ein fremder Posten wird als Vorgabe abgelehnt', function () {
    Livewire::test(Posten::class)->set('gerichteStationId', (string) $this->fremderPosten->id)->call('gerichtePostenSpeichern')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'nicht verfügbar'));

    expect(app(TeamSettingsService::class)->gerichtePostenId($this->rootTeam))->toBeNull();
});

it('★ Posten-Verwaltung: Schalter „Sammelposten (Pass)" setzt und löscht das Merkmal', function () {
    Livewire::test(Posten::class)
        ->assertSeeHtml('data-posten-sammelposten')
        ->call('feldSetzen', $this->pass->id, 'ist_sammelposten', '1');
    expect($this->pass->refresh()->ist_sammelposten)->toBeTrue();

    Livewire::test(Posten::class)->assertSeeHtml('data-posten-sammelposten-hinweis')->call('feldSetzen', $this->pass->id, 'ist_sammelposten', '0');
    expect($this->pass->refresh()->ist_sammelposten)->toBeFalse();
});

it('MCP: production_stations.GET zeigt Sammelposten-Flag und Team-Vorgabe, .PUT setzt das Flag (nur eigene Posten)', function () {
    app(TeamSettingsService::class)->update($this->rootTeam, ['gerichte_station_id' => $this->pass->id]);

    $put = $this->reg->get('foodalchemist.production_stations.PUT')->execute(['id' => $this->pass->id, 'ist_sammelposten' => true], $this->kontext);
    expect($put->success)->toBeTrue('PUT: '.($put->error ?? ''))->and($this->pass->refresh()->ist_sammelposten)->toBeTrue();

    $get = $this->reg->get('foodalchemist.production_stations.GET')->execute([], $this->kontext);
    $pass = collect($get->data['posten'])->firstWhere('slug', 'pass');
    expect($pass['ist_sammelposten'])->toBeTrue()
        ->and(collect($get->data['posten'])->firstWhere('slug', 'saucier')['ist_sammelposten'])->toBeFalse()
        ->and($get->data['gerichte_station_id'])->toBe((int) $this->pass->id);

    // fremder Posten: nicht sichtbar; geerbter (Oberteam) Posten: Vorlage, aus dem Unter-Team nicht änderbar
    expect($this->reg->get('foodalchemist.production_stations.PUT')->execute(['id' => $this->fremderPosten->id, 'ist_sammelposten' => true], $this->kontext)->errorCode)->toBe('NOT_FOUND');
    $kind = $this->makeUser($this->childA, 'Kind');
    $this->actingAs($kind);
    $geerbt = $this->reg->get('foodalchemist.production_stations.PUT')->execute(['id' => $this->pass->id, 'ist_sammelposten' => false], new ToolContext($kind, $this->childA));
    expect($geerbt->success)->toBeFalse()->and($geerbt->errorCode)->toBe('ACCESS_DENIED')
        ->and($this->pass->refresh()->ist_sammelposten)->toBeTrue();
});
