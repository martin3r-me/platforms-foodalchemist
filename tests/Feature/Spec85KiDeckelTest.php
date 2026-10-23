<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Exceptions\KiBudgetErschoepftException;
use Platform\FoodAlchemist\Exceptions\KiDeaktiviertException;
use Platform\FoodAlchemist\Exceptions\KiDeckelProjektException;
use Platform\FoodAlchemist\Models\FoodAlchemistMandant;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamKontingent;
use Platform\FoodAlchemist\Services\Ai\AiGatewayService;
use Platform\FoodAlchemist\Services\Ai\KiDeckelService;
use Platform\FoodAlchemist\Services\FaRechte;
use Platform\FoodAlchemist\Services\RecipeImageService;
use Platform\FoodAlchemist\Support\KiVerursacher;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * KI-Monatsdeckel je Mandant (Dominique 23.10.2026): Projekt-Mandanten und Teams ohne Mandanten-Zeile sind bei
 * `foodalchemist.ai.projekt_deckel_eur` (Standard 20 €) gedeckelt, der Master-Admin setzt je Mandant einen Betrag oder „unbegrenzt“.
 * Verbrauch = eingefrorene Kosten im Log (dieselbe Zuordnung wie die Rechnung). Gesperrt wird an allen KI-Stellen (Gateway, Sprache, Bild).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id, 'foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.projekt_deckel_eur' => 20]);
    \Platform\FoodAlchemist\Support\MasterTeam::flush();
    $this->kunde = Team::create(['name' => 'Kunde', 'user_id' => 1, 'personal_team' => false]);
    $this->standort = Team::create(['name' => 'Kunde Kita', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->kunde->id]);
    $this->svc = app(KiDeckelService::class);
    $this->verbrauch = function (Team $mandant, float $eur, ?string $am = null, string $verursacher = 'mandant') {
        DB::table('foodalchemist_ai_call_log')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'team_id' => $mandant->id, 'feature' => 'test', 'model' => 'gpt-test',
            'tokens_in' => 1, 'tokens_out' => 1, 'abrechnung_team_id' => $verursacher === 'mandant' ? $mandant->id : null, 'verursacher' => $verursacher,
            'kosten_usd' => $eur, 'usd_eur' => 1.0, 'created_at' => $am ?? now(), 'updated_at' => $am ?? now()]);
    };
});

it('Deckel: Projekt und ohne Zeile = Standard; Betrag am Kontingent schlägt ihn; unbegrenzt hebt auf; aktiv ohne Kontingent ungedeckelt', function () {
    expect($this->svc->deckel($this->kunde->id))->toBe(['eur' => 20.0, 'quelle' => 'projekt_standard']);   // ohne Mandanten-Zeile

    FoodAlchemistMandant::create(['team_id' => $this->kunde->id, 'status' => 'projekt']);
    expect($this->svc->deckel($this->kunde->id)['quelle'])->toBe('projekt_standard');

    FoodAlchemistTeamKontingent::create(['team_id' => $this->kunde->id, 'ki_budget_eur_monat' => 50]);
    expect($this->svc->deckel($this->kunde->id))->toBe(['eur' => 50.0, 'quelle' => 'kontingent']);

    FoodAlchemistTeamKontingent::where('team_id', $this->kunde->id)->update(['ki_deckel_unbegrenzt' => true]);
    expect($this->svc->deckel($this->kunde->id))->toBe(['eur' => null, 'quelle' => 'unbegrenzt']);

    FoodAlchemistTeamKontingent::where('team_id', $this->kunde->id)->delete();
    FoodAlchemistMandant::where('team_id', $this->kunde->id)->update(['status' => 'aktiv']);
    expect($this->svc->deckel($this->kunde->id))->toBe(['eur' => null, 'quelle' => 'ohne']);
});

it('Verbrauch: nur eingefrorene Kosten dieses Monats und dieses Mandanten (Plattform-Zeilen und Vormonat zählen nicht)', function () {
    ($this->verbrauch)($this->kunde, 7.5);
    ($this->verbrauch)($this->kunde, 100, now()->subMonthNoOverflow()->startOfMonth()->toDateTimeString());
    ($this->verbrauch)($this->kunde, 100, null, 'plattform');

    expect($this->svc->verbrauchMonat($this->kunde->id))->toBe(7.5);
});

it('Sperre: Standort eines Projekt-Mandanten ab 20 € gesperrt, klare Meldung; darunter frei', function () {
    $this->actingAs($this->makeUser($this->standort, 'Kita Koch'));
    ($this->verbrauch)($this->kunde, 19.99);
    $this->svc->pruefe();   // darunter: frei

    ($this->verbrauch)($this->kunde, 0.01);
    expect(fn () => $this->svc->pruefe())->toThrow(KiDeckelProjektException::class, 'KI-Kontingent der Projektphase (20,00 € im Monat) ist aufgebraucht');
    expect(new KiDeckelProjektException(20))->toBeInstanceOf(KiBudgetErschoepftException::class)->toBeInstanceOf(KiDeaktiviertException::class);
});

it('Sperre: Kontingent-Betrag → bisherige Budget-Meldung; unbegrenzt und aktive Mandanten nie; Master und Plattform-Lauf nie', function () {
    FoodAlchemistMandant::create(['team_id' => $this->kunde->id, 'status' => 'projekt']);
    FoodAlchemistTeamKontingent::create(['team_id' => $this->kunde->id, 'ki_budget_eur_monat' => 5]);
    ($this->verbrauch)($this->kunde, 6);
    $this->actingAs($this->makeUser($this->kunde, 'Kunde Koch'));
    expect(fn () => $this->svc->pruefe())->toThrow(KiBudgetErschoepftException::class);
    try { $this->svc->pruefe(); } catch (KiBudgetErschoepftException $e) { expect($e)->not->toBeInstanceOf(KiDeckelProjektException::class); }

    FoodAlchemistTeamKontingent::where('team_id', $this->kunde->id)->update(['ki_deckel_unbegrenzt' => true]);
    $this->svc->pruefe();

    FoodAlchemistTeamKontingent::where('team_id', $this->kunde->id)->delete();
    FoodAlchemistMandant::where('team_id', $this->kunde->id)->update(['status' => 'aktiv']);
    ($this->verbrauch)($this->kunde, 500);
    $this->svc->pruefe();   // aktiv: zahlt nach Verbrauch, kein Deckel

    $this->actingAs($this->makeUser($this->master, 'Kurator'));
    $this->svc->pruefe();
    Auth::logout();
    KiVerursacher::imKontext(KiVerursacher::KONTEXT_PLATTFORM, fn () => $this->svc->pruefe());
    expect(true)->toBeTrue();
});

it('Gateway: propose wirft bei erreichtem Projekt-Deckel, bevor der Provider gefragt wird', function () {
    $this->actingAs($this->makeUser($this->kunde, 'Kunde Koch'));
    ($this->verbrauch)($this->kunde, 25);
    $vorher = DB::table('foodalchemist_ai_call_log')->count();

    expect(fn () => app(AiGatewayService::class)->propose('gp.suggest', ['name' => 'Karotte']))->toThrow(KiDeckelProjektException::class);
    expect(DB::table('foodalchemist_ai_call_log')->count())->toBe($vorher);
});

it('Bild: die Bildgenerierung prüft den Deckel vor dem Core-Bilddienst', function () {
    $this->actingAs($this->makeUser($this->kunde, 'Kunde Koch'));
    ($this->verbrauch)($this->kunde, 25);
    $rezept = $this->makeRecipe($this->kunde, 'HG: Test', ['is_sales_recipe' => true]);
    app()->instance(\Platform\Core\Services\ImageGenerationService::class, Mockery::mock(\Platform\Core\Services\ImageGenerationService::class)->shouldNotReceive('generateAndStore')->getMock());

    expect(fn () => app(RecipeImageService::class)->produktFoto($this->kunde, $rezept))->toThrow(KiDeckelProjektException::class);
});

it('Anzeige: Kontingent-Nutzung und kiBudgetErschoepft rechnen mit denselben eingefrorenen Kosten', function () {
    ($this->verbrauch)($this->kunde, 21);
    $rechte = app(FaRechte::class);

    expect($rechte->kontingentNutzung($this->standort)['ki_eur_monat'])->toBe(21.0)
        ->and($rechte->kiBudgetErschoepft($this->standort))->toBeTrue()
        ->and($rechte->kiBudgetErschoepft($this->master))->toBeFalse();
});

it('Kunde unter dem Master-Team: zahlt selbst (abgerechnet + gedeckelt), sein Standort zahlt über ihn; der Master bleibt Plattform', function () {
    $unterMaster = Team::create(['name' => 'Kunde unter Master', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->master->id]);
    $dessenStandort = Team::create(['name' => 'Standort', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $unterMaster->id]);

    $this->actingAs($this->makeUser($dessenStandort, 'Koch'));
    expect(KiVerursacher::aufloesen())->toBe(['abrechnung_team_id' => $unterMaster->id, 'verursacher' => 'mandant']);

    ($this->verbrauch)($unterMaster, 20);
    expect(fn () => $this->svc->pruefe())->toThrow(KiDeckelProjektException::class);

    $this->actingAs($this->makeUser($this->master, 'Kurator'));
    expect(KiVerursacher::aufloesen()['verursacher'])->toBe('plattform');
});
