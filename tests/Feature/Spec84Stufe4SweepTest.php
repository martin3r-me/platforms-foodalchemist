<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistOutlet;
use Platform\FoodAlchemist\Models\FoodAlchemistPlanningFrameRule;
use Platform\FoodAlchemist\Models\FoodAlchemistSaison;
use Platform\FoodAlchemist\Models\FoodAlchemistServierform;
use Platform\FoodAlchemist\Services\CoverageService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Services\StandortService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 · Stufe-4-Sweep (Liste 00_INBOX/_Spec84_Stufe4_Sweep_Liste.md, Gruppe A). Je Fix ein Test aus fremdem Kontext:
 * - gespeicherte Verweise auf Master-Zeilen (Abo-Fassungen) lösen auf wie die Relation ('verweis') — A2 (SpeiseplanVorgabenTest), A3, A4
 * - handgebaute Ketten sind ab Stufe 4 an der Kundengrenze gekappt (Mandant unter dem Master) — A5, A6, A7
 * Bei Stufe 0 bleibt das heutige Verhalten (Gegenstück je Fall).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    $this->mandant = Team::create(['name' => 'Mandant unter Master', 'user_id' => 1, 'personal_team' => false, 'parent_team_id' => $this->master->id]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    TeamAncestryRegistry::flushAll();
    $this->herbst = FoodAlchemistSaison::create(['team_id' => $this->master->id, 'name' => 'Herbst']);
    $this->privat = fn (object $obj, string $methode, ...$args) => (new ReflectionMethod($obj, $methode))->invoke($obj, ...$args);
});

it('A3 PlanningFrame: Regeltext einer Abo-Regel nennt die Master-Saison auch im Mandanten-Kontext (Stufe 0 und 4)', function () {
    $regel = new FoodAlchemistPlanningFrameRule(['rule_type' => 'season_coverage', 'ref_id' => $this->herbst->id]);
    foreach ([0, 4] as $stufe) {
        config(['foodalchemist.team_sicht.stufe' => $stufe]);
        $text = FaTeam::imTeam($this->rootTeam, fn () => ($this->privat)(app(PlanningFrameService::class), 'ruleText', $regel));
        expect($text)->toBe('Saison-Abdeckung: Herbst', "Stufe {$stufe}");
    }
});

it('A4 Coverage: Befund einer Abo-Regel nennt die Master-Saison auch im Mandanten-Kontext (Stufe 0 und 4)', function () {
    $regel = new FoodAlchemistPlanningFrameRule(['rule_type' => 'season_coverage', 'ref_id' => $this->herbst->id, 'severity' => 'warn']);
    foreach ([0, 4] as $stufe) {
        config(['foodalchemist.team_sicht.stufe' => $stufe]);
        $befund = FaTeam::imTeam($this->rootTeam, fn () => ($this->privat)(app(CoverageService::class), 'pruefeRegel', $regel, collect(), null, ['saison_ids' => []]));
        expect(json_encode($befund, JSON_UNESCAPED_UNICODE))->toContain('Herbst')->not->toContain('Saison #', "Stufe {$stufe}");
    }
});

it('A5 MCP-Auflösung: Servierform/Facette des Masters löst beim Mandanten darunter ab Stufe 4 nicht mehr auf, eigene schon', function () {
    $masterForm = FoodAlchemistServierform::create(['team_id' => $this->master->id, 'code' => 'teller', 'label' => 'Teller']);
    $eigeneForm = FoodAlchemistServierform::create(['team_id' => $this->mandant->id, 'code' => 'schale', 'label' => 'Schale']);
    $anlass = DB::table('foodalchemist_event_types')->insertGetId(['uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
        'team_id' => $this->master->id, 'name' => 'Hochzeit', 'created_at' => now(), 'updated_at' => now()]);
    $tool = app(\Platform\FoodAlchemist\Tools\FoodbooksPostTool::class);
    $form = fn (string $w) => FaTeam::imTeam($this->mandant, function () use ($tool, $w) {
        try {
            return ($this->privat)($tool, 'resolveServierformId', $this->mandant, $w);
        } catch (\RuntimeException) {
            return null;   // „Unbekannt: … Verfügbar: …“
        }
    });
    $facette = fn () => FaTeam::imTeam($this->mandant, function () use ($tool) {
        try {
            return ($this->privat)($tool, 'resolveFacetId', $this->mandant, 'foodalchemist_event_types', 'Hochzeit');
        } catch (\RuntimeException) {
            return null;
        }
    });

    config(['foodalchemist.team_sicht.stufe' => 0]);   // heute: Kette ungekappt — der Master gehört dazu
    expect($form('teller'))->toBe((int) $masterForm->id)->and($facette())->toBe((int) $anlass);

    config(['foodalchemist.team_sicht.stufe' => 4]);
    expect($form('teller'))->toBeNull()->and($facette())->toBeNull()
        ->and($form('schale'))->toBe((int) $eigeneForm->id);
});

it('A6 Standort: zugeordneter Betrieb des Masters gilt beim Mandanten darunter ab Stufe 4 nicht — auch aus dem Master-Kontext gelesen', function () {
    $betrieb = FoodAlchemistOutlet::create(['team_id' => $this->master->id, 'name' => 'Master-Küche']);
    DB::table('foodalchemist_team_betriebe')->insert(['team_id' => $this->mandant->id, 'outlet_id' => $betrieb->id, 'created_at' => now(), 'updated_at' => now()]);
    $lies = fn () => FaTeam::imTeam($this->master, fn () => app(StandortService::class)->zugeordneterBetrieb($this->mandant)?->id);

    config(['foodalchemist.team_sicht.stufe' => 0]);
    expect($lies())->toBe((int) $betrieb->id);
    config(['foodalchemist.team_sicht.stufe' => 4]);
    expect($lies())->toBeNull();
});

it('A7 Betriebsprofil: Betrieb des Masters ist für den Mandanten darunter ab Stufe 4 kein eigenes Profil — auch aus dem Master-Kontext', function () {
    $betrieb = FoodAlchemistOutlet::create(['team_id' => $this->master->id, 'name' => 'Master-Küche']);
    $zeile = fn () => FaTeam::imTeam($this->master, fn () => ($this->privat)(app(TeamSettingsService::class), 'outletRow', $this->mandant, $betrieb));

    config(['foodalchemist.team_sicht.stufe' => 0]);
    expect($zeile())->not->toBeNull();
    config(['foodalchemist.team_sicht.stufe' => 4]);
    expect($zeile())->toBeNull();
});
