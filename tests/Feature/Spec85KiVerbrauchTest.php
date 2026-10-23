<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Settings\Ki;
use Platform\FoodAlchemist\Services\Ai\KiAbrechnungService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Platform\FoodAlchemist\Tools\KiVerbrauchGetTool;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * KI-Abrechnung PR 3 (Dominique 23.10.2026): der Mandant sieht seinen KI-Verbrauch in Euro + Aufrufen. Ein Standort sieht nur sich,
 * das Kunden-Hauptteam den ganzen Mandanten und je Standort; das Master-Team behält seine Abrechnung je Mandant.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id, 'foodalchemist.ai.projekt_deckel_eur' => 20]);
    \Platform\FoodAlchemist\Support\MasterTeam::flush();
    $this->kunde = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    $this->kita = Team::create(['name' => 'Broich Kita', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->kunde->id]);
    $this->fremd = Team::create(['name' => 'Fremd', 'user_id' => 1, 'personal_team' => false]);
    $this->log = function (Team $daten, Team $zahler, float $usd, string $tier = 'M', ?string $error = null, string $verursacher = 'mandant') {
        DB::table('foodalchemist_ai_call_log')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $daten->id, 'feature' => 'test', 'tier' => $tier,
            'model' => 'gpt-test', 'tokens_in' => 10, 'tokens_out' => 10, 'error' => $error,
            'abrechnung_team_id' => $verursacher === 'mandant' ? $zahler->id : null, 'verursacher' => $verursacher,
            'kosten_usd' => $usd, 'usd_eur' => 1.0, 'created_at' => now(), 'updated_at' => now()]);
    };
    ($this->log)($this->kunde, $this->kunde, 3.0, 'M');
    ($this->log)($this->kita, $this->kunde, 2.0, 'S');
    ($this->log)($this->kita, $this->kunde, 0.5, 'S', 'timeout');      // Fehllauf mit Anbieter-Kosten
    ($this->log)($this->fremd, $this->fremd, 50.0);                     // anderer Mandant
    ($this->log)($this->kunde, $this->kunde, 99.0, 'M', null, 'plattform');   // Plattform-Lauf, zahlt nicht der Mandant
    $this->svc = app(KiAbrechnungService::class);
});

it('Hauptteam sieht den ganzen Mandanten und je Standort; Fehlläufe kosten mit, zählen aber nicht als Aufruf', function () {
    $v = $this->svc->fuerTeam($this->kunde, now()->startOfMonth(), now());

    expect($v['ist_haupt'])->toBeTrue()->and($v['summe']['eur'])->toBe(5.5)->and($v['summe']['calls'])->toBe(3)->and($v['summe']['fehler'])->toBe(1)
        ->and(collect($v['standorte'])->pluck('eur', 'team_id')->all())->toBe([$this->kunde->id => 3.0, $this->kita->id => 2.5])
        ->and($v['deckel'])->toMatchArray(['eur' => 20.0, 'quelle' => 'projekt_standard', 'verbrauch_monat' => 5.5]);
});

it('Standort sieht nur sich, ohne Standort-Liste und ohne Deckel', function () {
    $v = $this->svc->fuerTeam($this->kita, now()->startOfMonth(), now());

    expect($v['ist_haupt'])->toBeFalse()->and($v['summe']['eur'])->toBe(2.5)->and($v['standorte'])->toBe([])->and($v['deckel'])->toBeNull();
});

it('Einstellungen → KI: Karte für Hauptteam (mit Deckel-Balken) und Standort; das Master-Team sieht sie nicht', function () {
    $this->actingAs($this->makeUser($this->kunde, 'Broich Admin', 'admin'));
    Livewire::test(Ki::class)->assertSeeHtml('data-ki-verbrauch')->assertSeeHtml('data-ki-verbrauch-deckel')
        ->assertSeeHtml('data-ki-verbrauch-standort="'.$this->kita->id.'"')->assertSee('5,50 €')->assertSee('Embeddings');

    $this->actingAs($this->makeUser($this->kita, 'Kita Koch'));
    Livewire::test(Ki::class)->assertSeeHtml('data-ki-verbrauch')->assertDontSeeHtml('data-ki-verbrauch-deckel')->assertSee('2,50 €')->assertDontSee('5,50 €');

    $this->actingAs($this->makeUser($this->master, 'Kurator', 'admin'));
    Livewire::test(Ki::class)->assertDontSeeHtml('data-ki-verbrauch-tabelle');
});

it('MCP ki_verbrauch.GET liefert dieselben Zahlen wie die Karte', function () {
    $user = $this->makeUser($this->kunde, 'Broich Admin', 'admin');
    $r = app(KiVerbrauchGetTool::class)->execute([], new ToolContext($user, $this->kunde));

    expect($r->success)->toBeTrue()->and($r->data['summe'])->toMatchArray(['aufrufe' => 2, 'fehllaeufe' => 1, 'eur' => 5.5])
        ->and($r->data['ist_hauptteam'])->toBeTrue()->and(count($r->data['je_standort']))->toBe(2);
});
