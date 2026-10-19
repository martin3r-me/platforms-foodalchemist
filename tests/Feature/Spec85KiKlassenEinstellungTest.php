<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\FaRechtFehltException;
use Platform\FoodAlchemist\Livewire\Settings\Ki;
use Platform\FoodAlchemist\Models\FoodAlchemistKiKlasseOverride;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\KiKlassenEinstellung;
use Platform\FoodAlchemist\Tests\Support\SeedsKanon;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\KiKlassenGetTool;
use Platform\FoodAlchemist\Tools\KiKlassenPutTool;

uses(TestCase::class, SeedsTeamHierarchy::class, SeedsKanon::class);

/**
 * Spec 85 (Dominique 19.10.): KI-Klasse je Arbeitsschritt einstellbar. Eingestellt > Code, an EINER Lesestelle (je Request gelesen);
 * global; nur Admin im Master-Team stellt ein, Mandanten sehen keine Auswahl; unbekannter Schritt abgelehnt.
 * `vk.speisen_klasse` ist im Code „leicht“.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id, 'foodalchemist.ai.provider' => 'fake']);
    $this->admin = $this->makeUser($this->master, 'Plattform Admin', 'owner');
    $this->e = fn () => app(KiKlassenEinstellung::class);
    $this->tier = fn () => DB::table('foodalchemist_ai_call_log')->orderByDesc('id')->value('tier');
});

it('ohne Einstellung gilt die Klasse aus dem Code; eingestellt gewinnt im Gateway-Call; Standard setzt zurück', function () {
    $this->actingAs($this->admin);
    expect(($this->e)()->klasseFuer('vk.speisen_klasse'))->toBe('leicht');

    ($this->e)()->setze($this->master, 'vk.speisen_klasse', 'stark', 'Test');
    expect(($this->e)()->klasseFuer('vk.speisen_klasse'))->toBe('stark');   // Speicher nach dem Setzen geleert
    app(AiGatewayService::class)->propose('vk.speisen_klasse', ['name' => 'X']);
    expect(($this->tier)())->toBe('S');

    ($this->e)()->setze($this->master, 'vk.speisen_klasse', null);
    app(AiGatewayService::class)->propose('vk.speisen_klasse', ['name' => 'X']);
    expect(($this->e)()->klasseFuer('vk.speisen_klasse'))->toBe('leicht')->and(($this->tier)())->toBe('L')
        ->and(FoodAlchemistKiKlasseOverride::where('feature_key', 'vk.speisen_klasse')->value('klasse'))->toBeNull();   // Zeile bleibt (Verlauf)
});

it('je Request gelesen: eine Änderung an der Tabelle wirkt erst nach dem Leeren bzw. im nächsten Request', function () {
    $this->actingAs($this->admin);
    expect(($this->e)()->klasseFuer('vk.speisen_klasse'))->toBe('leicht');
    FoodAlchemistKiKlasseOverride::create(['feature_key' => 'vk.speisen_klasse', 'klasse' => 'mittel']);   // an der Einstellung vorbei

    expect(($this->e)()->klasseFuer('vk.speisen_klasse'))->toBe('leicht');   // Request-Speicher
    app()->forgetScopedInstances();
    expect(($this->e)()->klasseFuer('vk.speisen_klasse'))->toBe('mittel');
});

it('Rechte: nur Admin im Master-Team; Mandanten-Admin und Master-Mitglied werden abgelehnt; unbekannter Schritt und Klasse abgelehnt', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Mandant Admin', 'owner'));
    expect(fn () => ($this->e)()->setze($this->rootTeam, 'vk.speisen_klasse', 'stark'))->toThrow(FaRechtFehltException::class);

    $this->actingAs($this->makeUser($this->master, 'Master Mitglied', 'member'));
    expect(fn () => ($this->e)()->setze($this->master, 'vk.speisen_klasse', 'stark'))->toThrow(FaRechtFehltException::class);

    $this->actingAs($this->admin);
    expect(fn () => ($this->e)()->setze($this->master, 'gibt.es.nicht', 'stark'))->toThrow(InvalidArgumentException::class, 'Unbekannter Arbeitsschritt')
        ->and(fn () => ($this->e)()->setze($this->master, 'vk.speisen_klasse', 'billig'))->toThrow(InvalidArgumentException::class, 'Ungültige Klasse')
        ->and(FoodAlchemistKiKlasseOverride::count())->toBe(0);
});

it('Einstellungen › KI: Auswahl nur für Admin im Master-Team; Mandant sieht die Zuordnung ohne Auswahl', function () {
    $this->actingAs($this->admin);
    $this->admin->update(['current_team_id' => $this->master->id]);
    Livewire::test(Ki::class)->assertSeeHtml('data-ki-klassen-pflege')->assertSeeHtml('data-kk-select="vk.speisen_klasse"')
        ->assertSee('Standard (Code: Leicht)')
        ->call('klasseSetzen', 'vk.speisen_klasse', 'mittel');
    expect(FoodAlchemistKiKlasseOverride::where('feature_key', 'vk.speisen_klasse')->value('klasse'))->toBe('mittel');

    $this->actingAs($this->makeUser($this->rootTeam, 'Mandant Admin', 'owner'));
    Livewire::test(Ki::class)->assertSeeHtml('data-ki-klassen')->assertDontSeeHtml('data-ki-klassen-pflege')->assertDontSeeHtml('data-kk-select');
});

it('MCP: GET liest wirksam/Standard/eingestellt; PUT nur aus dem Master-Team mit Admin; standard setzt zurück', function () {
    $mandant = new ToolContext($this->makeUser($this->rootTeam, 'Mandant Admin', 'owner'), $this->rootTeam);
    $this->actingAs($mandant->user);
    $nein = app(KiKlassenPutTool::class)->execute(['prompt_key' => 'vk.speisen_klasse', 'klasse' => 'stark'], $mandant);
    expect($nein->success)->toBeFalse()->and($nein->errorCode)->toBe('FORBIDDEN');

    $ctx = new ToolContext($this->admin, $this->master);
    $this->actingAs($this->admin);
    $ja = app(KiKlassenPutTool::class)->execute(['prompt_key' => 'vk.speisen_klasse', 'klasse' => 'mittel', 'grund' => 'Qualität'], $ctx);
    expect($ja->success)->toBeTrue()->and($ja->data['klasse'])->toBe('mittel')->and($ja->data['standard'])->toBe('leicht');

    $get = app(KiKlassenGetTool::class)->execute(['prompt_key' => 'vk.speisen_klasse'], $mandant);   // lesen darf jedes Team
    expect($get->data['items'][0])->toMatchArray(['klasse' => 'mittel', 'standard' => 'leicht', 'eingestellt' => true]);

    expect(app(KiKlassenPutTool::class)->execute(['prompt_key' => 'vk.speisen_klasse', 'klasse' => 'standard'], $ctx)->data['eingestellt'])->toBeFalse()
        ->and(app(KiKlassenPutTool::class)->execute(['prompt_key' => 'x.y', 'klasse' => 'stark'], $ctx)->success)->toBeFalse();
});
