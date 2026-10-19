<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Exceptions\GeerbtNurLesenException;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkGpProposal;
use Platform\FoodAlchemist\Models\FoodAlchemistBulkProposal;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\BulkEnrichService;
use Platform\FoodAlchemist\Services\ConceptGeneratorService;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\FeedbackService;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Services\PlanningCascadeService;
use Platform\FoodAlchemist\Services\PlanningFrameService;
use Platform\FoodAlchemist\Services\RecipeOneShotService;
use Platform\FoodAlchemist\Services\RecipeService;
use Platform\FoodAlchemist\Services\SpeisenKlassenService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 84 B2 · Eigentum je Schreibweg (Service-Scan, Prüfliste docs/PLANUNG/84_B2_Pruefliste.md §4).
 *
 * Kind-Team A sieht die Zeilen des Oberteams (Team-Kette), besitzt sie aber nicht. Jeder Weg unten schrieb bis
 * hierher auf eine nur SICHTBARE Zeile. Je Weg: fremd → abgewiesen und unverändert; eigen → erlaubt.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->oben = $this->rootTeam;
    $this->kind = $this->childA;
    $this->actingAs($this->makeUser($this->kind, 'Kind Koch'));
    config(['foodalchemist.ai.provider' => 'fake', 'foodalchemist.ai.backoff' => []]);

    $this->run = fn ($team) => DB::table('foodalchemist_bulk_runs')->insertGetId([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $team->id, 'status' => 'running', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->anker = function (): int {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => 'zitrone-' . uniqid(), 'display_de' => 'Zitrone',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
});

it('Spec 84 B2 · BulkEnrich uebernehmen: KI-Vorschlag landet nicht auf einem nur sichtbaren Rezept, wohl auf dem eigenen', function () {
    $fremd = $this->makeRecipe($this->oben, 'Oberteam-Fond');
    $eigen = $this->makeRecipe($this->kind, 'Eigener Fond');
    $run = ($this->run)($this->kind);
    $mk = fn ($r) => FoodAlchemistBulkProposal::create(['team_id' => $this->kind->id, 'run_id' => $run, 'recipe_id' => $r->id,
        'field' => 'description', 'value' => 'KI-Text', 'status' => 'offen']);
    $svc = app(BulkEnrichService::class);

    expect($svc->uebernehmen($this->kind, $mk($fremd)->id))->toBeFalse()
        ->and(FoodAlchemistRecipe::find($fremd->id)->description)->not->toBe('KI-Text')
        ->and($svc->uebernehmen($this->kind, $mk($eigen)->id))->toBeTrue()
        ->and(FoodAlchemistRecipe::find($eigen->id)->description)->toBe('KI-Text');
});

it('Spec 84 B2 · BulkEnrich: Vorschläge eines anderen Teams lassen sich weder übernehmen noch verwerfen', function () {
    $r = $this->makeRecipe($this->oben, 'Oberteam-Fond');
    $gp = $this->makeGp($this->oben, 'Oberteam-GP');
    $run = ($this->run)($this->oben);
    $p = FoodAlchemistBulkProposal::create(['team_id' => $this->oben->id, 'run_id' => $run, 'recipe_id' => $r->id, 'field' => 'description', 'value' => 'x', 'status' => 'offen']);
    $g = FoodAlchemistBulkGpProposal::create(['team_id' => $this->oben->id, 'run_id' => $run, 'gp_id' => $gp->id, 'field' => 'tags', 'value' => ['x'], 'status' => 'offen']);
    $svc = app(BulkEnrichService::class);

    $svc->verwerfen($this->kind, $p->id);
    $svc->verwerfenGp($this->kind, $g->id);

    expect($svc->uebernehmen($this->kind, $p->id))->toBeFalse()
        ->and($svc->uebernehmenGp($this->kind, $g->id))->toBeFalse()
        ->and(FoodAlchemistBulkProposal::find($p->id)->status->value)->toBe('offen')
        ->and(FoodAlchemistBulkGpProposal::find($g->id)->status->value)->toBe('offen');
});

it('Spec 84 B2 · GP-Anker: nicht über Mandantengrenzen (globales GP ohne Master-Recht); eigene Kette bleibt erlaubt', function () {
    $global = \Platform\FoodAlchemist\Models\FoodAlchemistGp::create(['team_id' => null, 'gp_key' => 'b2|global|anker', 'name' => 'Zitrone global']);
    $oben = $this->makeGp($this->oben, 'Zitrone Oberteam');
    $a = ($this->anker)();
    DB::table('foodalchemist_gp_anchor_mappings')->insert(['uuid' => (string) UuidV7::generate(), 'team_id' => null, 'gp_id' => $global->id,
        'anchor_id' => $a, 'role' => 'kern', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    $svc = app(PairingService::class);
    $vorher = DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $global->id)->get()->toJson();

    expect(fn () => $svc->setGpAnker($this->kind, $global->id, $a, 'neben'))->toThrow(GeerbtNurLesenException::class)
        ->and(fn () => $svc->removeGpAnker($this->kind, $global->id, $a))->toThrow(GeerbtNurLesenException::class)
        ->and(fn () => $svc->clearGpAnker($this->kind, $global->id))->toThrow(GeerbtNurLesenException::class)
        ->and(fn () => $svc->setGpAnkerInference($this->kind, $global->id, ($this->anker)(), 0.9))->toThrow(GeerbtNurLesenException::class)
        ->and(DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $global->id)->get()->toJson())->toBe($vorher);

    $svc->setGpAnker($this->kind, $oben->id, $a);   // Unter-Team pflegt das Oberteam-GP: bewusst erlaubt
    expect(DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $oben->id)->exists())->toBeTrue();
});

it('Spec 84 B2 · Anreichern: weder der Kaskaden-Import noch die Einzel-Anreicherung schreiben auf ein fremdes Rezept', function () {
    $fremd = $this->makeRecipe($this->oben, 'Oberteam-Gericht', ['is_sales_recipe' => true]);
    \Illuminate\Support\Facades\Queue::fake();
    $laeufe = DB::table('foodalchemist_cascade_runs')->count();

    expect(fn () => app(PlanningCascadeService::class)->enrichBestehendesRezept($this->kind, $fremd->id, true))->toThrow(GeerbtNurLesenException::class)
        ->and(DB::table('foodalchemist_cascade_runs')->count())->toBe($laeufe)
        ->and(fn () => app(RecipeOneShotService::class)->anreichern($this->kind, $fremd))->toThrow(GeerbtNurLesenException::class);
    \Illuminate\Support\Facades\Queue::assertNothingPushed();
});

it('Spec 84 B2 · Teller-Rollen übernehmen nur am eigenen Rezept', function () {
    $fremd = $this->makeRecipe($this->oben, 'Oberteam-Gericht', ['is_sales_recipe' => true]);
    $z = $this->makeIngredient($fremd, 'Kartoffel');

    expect(fn () => app(SpeisenKlassenService::class)->acceptRollen($this->kind, $fremd->id, [$z->id => 'beilage']))->toThrow(GeerbtNurLesenException::class)
        ->and(DB::table('foodalchemist_recipe_ingredients')->where('id', $z->id)->value('role_source'))->not->toBe('ki');
});

it('Spec 84 B2 · Generator-Stubs: fremden Eltern-Stub nicht auf draft setzen', function () {
    $stub = $this->makeRecipe($this->oben, 'Oberteam-Stub', ['status' => 'stub', 'last_modified_by' => 'generator_stub']);
    $svc = app(RecipeService::class);

    $svc->createSubRecipeStub($this->kind, 'Neue Komponente Spec84', $stub->id);
    expect(FoodAlchemistRecipe::find($stub->id)->status->value)->toBe('stub');

    $eigenerStub = $this->makeRecipe($this->kind, 'Eigener Stub', ['status' => 'stub', 'last_modified_by' => 'generator_stub']);
    $svc->createSubRecipeStub($this->kind, 'Zweite Komponente Spec84', $eigenerStub->id);
    expect(FoodAlchemistRecipe::find($eigenerStub->id)->status->value)->toBe('draft');
});

it('Spec 84 B2 · Feedback weiterentwickeln nur aus eigenem Feedback', function () {
    $r = $this->makeRecipe($this->oben, 'Oberteam-Gericht', ['is_sales_recipe' => true]);
    $fb = app(FeedbackService::class)->erstelle($this->oben, $r->id, ['quelle' => 'kunde', 'comment' => 'Mehr Säure']);
    $rezepte = FoodAlchemistRecipe::count();

    expect(fn () => app(FeedbackService::class)->weiterentwickeln($this->kind, $fb->id))->toThrow(GeerbtNurLesenException::class)
        ->and(DB::table('foodalchemist_recipe_feedback')->where('id', $fb->id)->value('spawned_recipe_id'))->toBeNull()
        ->and(FoodAlchemistRecipe::count())->toBe($rezepte);

    $eigenes = app(FeedbackService::class)->erstelle($this->kind, $r->id, ['quelle' => 'kueche', 'comment' => 'Weniger Salz', 'score' => 3]);
    $neu = app(FeedbackService::class)->weiterentwickeln($this->kind, $eigenes->id);
    expect($neu->team_id)->toBe($this->kind->id);
});

it('Spec 84 B2 · Assemblierung übernehmen: kein Gerüst an ein fremdes Konzept, bevor der Eigentümer geprüft ist', function () {
    $fremd = app(ConceptService::class)->create($this->oben, ['name' => 'Oberteam-Entwurf', 'status' => 'draft']);
    $fb = FoodAlchemistFoodbook::create(['team_id' => $this->kind->id, 'label' => 'Kind-FB']);
    $frame = app(PlanningFrameService::class)->frameFor($this->kind, 'foodbook', $fb->id);
    app(PlanningFrameService::class)->addSlot($this->kind, $frame, ['label' => 'Hauptgang', 'target_count' => 1]);
    $frame->refresh();
    $geruestVorher = DB::table('foodalchemist_planning_frames')->where('owner_type', 'concept')->where('owner_id', $fremd->id)->count();

    expect(fn () => app(ConceptGeneratorService::class)->uebernehmeAssemblierung($this->kind, $frame, $fremd->id))->toThrow(GeerbtNurLesenException::class)
        ->and(DB::table('foodalchemist_planning_frames')->where('owner_type', 'concept')->where('owner_id', $fremd->id)->count())->toBe($geruestVorher);
});
