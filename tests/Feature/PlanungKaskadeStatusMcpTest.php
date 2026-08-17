<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRun;
use Platform\FoodAlchemist\Models\FoodAlchemistCascadeRunStep;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Etappe 9 (Roadmap »Mise en Place«) — MCP-Kaskaden-Status, READ-ONLY.
 *
 * Beweisziele:
 *  1. Registry-Smoke: das Tool ist unter `foodalchemist.planung_kaskade.GET` registriert + read-only.
 *  2. GET liefert Lauf-Kopf + Ebenen-Aggregat + Schritte + Handlungs-Hinweis (aus dem Run-Status).
 *  3. Anreicherungs-/Bild-Status aus `deferred` werden je Schritt sichtbar.
 *  4. Tenancy (Reads visibleToTeam): ein NICHT sichtbarer Lauf (Schwester-Team) → NOT_FOUND.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->ctx = new ToolContext($this->user, $this->rootTeam);
});

it('Registry-Smoke: planung_kaskade.GET ist registriert + read-only', function () {
    $tool = $this->registry->get('foodalchemist.planung_kaskade.GET');
    expect($tool)->not->toBeNull()
        ->and($tool->getName())->toBe('foodalchemist.planung_kaskade.GET')
        ->and($tool->getMetadata()['read_only'])->toBeTrue()
        ->and($tool->getMetadata()['risk_level'])->toBe('safe');
});

it('GET liefert Lauf-Kopf + Ebenen-Aggregat + Schritte + Hinweis', function () {
    $run = FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'scope' => 'gericht', 'status' => 'review', 'staged' => true,
    ]);
    // gericht-Stufe: ein fertiger Draft (wartet auf Freigabe) + ein geplantes Sub-Rezept.
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht',
        'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => 77, 'label' => 'Zanderfilet', 'sort' => 1,
    ]);
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept',
        'status' => 'geplant', 'label' => 'Rotwein-Jus', 'depth' => 1, 'sort' => 2,
    ]);

    $r = $this->registry->get('foodalchemist.planung_kaskade.GET')->execute(['run_id' => $run->id], $this->ctx);

    expect($r->success)->toBeTrue()
        ->and($r->data['lauf']['id'])->toBe((int) $run->id)
        ->and($r->data['lauf']['scope'])->toBe('gericht')
        ->and($r->data['lauf']['status'])->toBe('review')
        ->and($r->data['lauf']['gestuft'])->toBeTrue()
        ->and($r->data['hinweis'])->toContain('human-only');

    // Schritte in sort-Reihenfolge, label + status durchgereicht.
    expect($r->data['schritte'])->toHaveCount(2)
        ->and($r->data['schritte'][0]['label'])->toBe('Zanderfilet')
        ->and($r->data['schritte'][0]['status'])->toBe('done')
        ->and($r->data['schritte'][0]['ref_id'])->toBe(77)
        ->and($r->data['schritte'][1]['label'])->toBe('Rotwein-Jus')
        ->and($r->data['schritte'][1]['status'])->toBe('geplant');

    // Ebenen-Aggregat: gericht → 1 entwurf_offen, rezept → 1 geplant.
    $stufen = collect($r->data['stufen'])->keyBy('ebene');
    expect($stufen['gericht']['entwurf_offen'])->toBe(1)
        ->and($stufen['gericht']['gesamt'])->toBe(1)
        ->and($stufen['rezept']['geplant'])->toBe(1);
});

it('GET zeigt Anreicherungs-/Bild-Status aus deferred', function () {
    $run = FoodAlchemistCascadeRun::create([
        'team_id' => $this->rootTeam->id, 'scope' => 'rezept', 'status' => 'review',
    ]);
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->rootTeam->id, 'cascade_run_id' => $run->id, 'kind' => 'rezept',
        'status' => 'done', 'ref_type' => 'recipe', 'ref_id' => 5, 'label' => 'Sud',
        'deferred' => ['enrich' => ['status' => 'done'], 'bilder' => ['status' => 'failed', 'n' => 0]],
    ]);

    $r = $this->registry->get('foodalchemist.planung_kaskade.GET')->execute(['run_id' => $run->id], $this->ctx);

    expect($r->success)->toBeTrue()
        ->and($r->data['schritte'][0]['anreicherung'])->toBe('done')
        ->and($r->data['schritte'][0]['bilder'])->toBe('failed');
});

it('Tenancy: ein nicht sichtbarer Lauf (Schwester-Team) liefert NOT_FOUND', function () {
    // childB besitzt den Lauf; childA (Schwester) sieht ihn NICHT (Reads visibleToTeam).
    $run = FoodAlchemistCascadeRun::create([
        'team_id' => $this->childB->id, 'scope' => 'gericht', 'status' => 'running',
    ]);
    FoodAlchemistCascadeRunStep::create([
        'team_id' => $this->childB->id, 'cascade_run_id' => $run->id, 'kind' => 'gericht', 'status' => 'running',
    ]);

    $childAUser = $this->makeUser($this->childA, 'Kind A User');
    $ctxA = new ToolContext($childAUser, $this->childA);

    $r = $this->registry->get('foodalchemist.planung_kaskade.GET')->execute(['run_id' => $run->id], $ctxA);

    expect($r->success)->toBeFalse()
        ->and($r->errorCode)->toBe('NOT_FOUND');
});
