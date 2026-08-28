<?php

use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Speiseplan\Editor;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplan;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanEintrag;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeiseplanLinie;
use Platform\FoodAlchemist\Services\PresentationService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 43 (Phase 3) — Speiseplan-Präsentation (GV-Aushang, Wochen-Raster): Service-Sanitizer
 * (preislos + LMIV pflicht), Public-Route, MCP, Editor-Tab.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->pres = app(PresentationService::class);

    $this->bauePlan = function ($team) {
        $mo = Carbon::parse('2027-06-07')->startOfWeek(Carbon::MONDAY); // fixer Montag
        $plan = FoodAlchemistSpeiseplan::create([
            'team_id' => $team->id, 'name' => 'Wochenplan KW23', 'status' => 'draft', 'start_date' => $mo->format('Y-m-d'),
        ]);
        $line = FoodAlchemistSpeiseplanLinie::create([
            'team_id' => $team->id, 'menu_plan_id' => $plan->id, 'name' => 'Menü 1', 'sort_order' => 1,
        ]);
        $dish = $this->makeRecipe($team, 'HG Rindergulasch', ['is_sales_recipe' => true, 'sales_net' => 4.5]);
        FoodAlchemistSpeiseplanEintrag::create([
            'team_id' => $team->id, 'menu_plan_id' => $plan->id, 'line_id' => $line->id,
            'week' => 1, 'weekday' => 1, 'meal' => 'mittag', 'entry_date' => $mo->format('Y-m-d'),
            'sales_recipe_id' => $dish->id, 'position' => 1,
        ]);

        return $plan;
    };
});

it('buildSnapshot des Speiseplans: Grid + LMIV, preislos, interna-frei', function () {
    $plan = ($this->bauePlan)($this->rootTeam);
    $snap = $this->pres->buildSnapshot($this->rootTeam, $plan, 'speiseplan', ['design' => 'kiosk']);

    expect($snap['title'])->toBe('Wochenplan KW23')
        ->and($snap['content']['layout_kind'])->toBe('grid')
        ->and($snap['content']['grid']['lines'])->not->toBeEmpty()
        ->and($snap['settings']['declaration'])->toBeTrue()   // LMIV Pflicht
        ->and($snap['settings']['price_display'])->toBeFalse(); // GV preislos

    // Der Grid-Block ist in der aufgelösten Layout-Definition (statt chapter_loop).
    $blockTypes = collect($snap['resolved_design']['layout'])->pluck('block_type');
    expect($blockTypes)->toContain('grid');

    $keys = [];
    $walk = function ($n) use (&$walk, &$keys) {
        foreach ($n as $k => $v) {
            if (is_string($k)) {
                $keys[] = $k;
            }
            if (is_array($v)) {
                $walk($v);
            }
        }
    };
    $walk($snap['content']);
    foreach (['preis_quelle', 'kaskaden', 'intern', 'ek', 'plan'] as $verboten) {
        expect($keys)->not->toContain($verboten);
    }
});

it('öffentlicher Speiseplan-Aushang ohne Login + Grid-Rendering + 404-Matrix', function () {
    $plan = ($this->bauePlan)($this->rootTeam);
    $res = $this->pres->publish($this->rootTeam, 'speiseplan', $plan->id, ['expires_at' => now()->addDays(30)->toDateString()]);

    $this->get('/p/speiseplan/' . $res['token'])
        ->assertOk()->assertSee('Wochenplan KW23')->assertSee('HG Rindergulasch')->assertSee('Menü 1')
        ->assertDontSee('preis_quelle')->assertDontSee('Wareneinsatz');

    $this->pres->withdraw($this->rootTeam, 'speiseplan', $plan->id);
    $this->get('/p/speiseplan/' . $res['token'])->assertNotFound();
});

it('Speiseplan-MCP: PUBLISH → GET + Public erreichbar; Registry-Smoke; fremd → NOT_FOUND', function () {
    foreach (['PUBLISH', 'WITHDRAW', 'GET'] as $verb) {
        expect($this->registry->get('foodalchemist.speiseplan_presentation.' . $verb))->not->toBeNull($verb);
    }

    $plan = ($this->bauePlan)($this->rootTeam);
    $pub = $this->registry->get('foodalchemist.speiseplan_presentation.PUBLISH')->execute([
        'speiseplan_id' => $plan->id, 'expires_at' => now()->addDays(30)->toDateString(),
    ], $this->kontext);
    expect($pub->success)->toBeTrue();
    $this->get('/p/speiseplan/' . $pub->data['token'])->assertOk();

    $get = $this->registry->get('foodalchemist.speiseplan_presentation.GET')->execute(['speiseplan_id' => $plan->id], $this->kontext);
    expect($get->data['live'])->toBeTrue();

    $fremd = ($this->bauePlan)($this->childB);
    $kontextA = new ToolContext($this->makeUser($this->childA), $this->childA);
    $res = $this->registry->get('foodalchemist.speiseplan_presentation.PUBLISH')->execute([
        'speiseplan_id' => $fremd->id, 'expires_at' => now()->addDays(30)->toDateString(),
    ], $kontextA);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND');
});

it('Editor-Tab: Branding speichern + Veröffentlichen (Pflicht-Datum)', function () {
    $plan = ($this->bauePlan)($this->rootTeam);

    Livewire::test(Editor::class)
        ->set('planId', $plan->id)
        ->set('brandColor', '#0055aa')
        ->call('brandingSpeichern')
        ->set('presentationGueltigBis', now()->addDays(20)->toDateString())
        ->call('veroeffentlichen');

    $plan->refresh();
    expect($plan->brand_color)->toBe('#0055aa')
        ->and($plan->presentation_enabled)->toBeTrue();

    // Ohne Datum: kein Publish.
    $plan2 = ($this->bauePlan)($this->rootTeam);
    $c = Livewire::test(Editor::class)->set('planId', $plan2->id)->set('presentationGueltigBis', null)->call('veroeffentlichen');
    expect($c->get('presentationFehler'))->not->toBeNull();
    expect($plan2->refresh()->presentation_enabled)->toBeFalse();
});
