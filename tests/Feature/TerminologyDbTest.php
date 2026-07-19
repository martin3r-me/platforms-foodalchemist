<?php

use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\FoodAlchemist\Livewire\ReviewQueue;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistTerminologyAlias;
use Platform\FoodAlchemist\Models\FoodAlchemistTerminologyAntiMarker;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Services\TerminologyService;
use Platform\FoodAlchemist\Tools\TerminologyListTool;
use Platform\FoodAlchemist\Tools\TerminologyPostTool;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * #507 Weg-2 · E7-b: Promotion der Terminologie-Schicht auf runtime-pflegbare DB-Tabellen
 * (additiv über die Konstanten-Baseline). Beweist: DB-Zeilen wirken sofort in
 * aliasPhrasesFor/isAntiMarker UND fließen bis in die matchIngredient-Entscheidung —
 * das Fundament der E7-c-Lernschleife für neue Namen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
});

it('DB-Alias-Gruppe wirkt additiv in aliasPhrasesFor (nicht in den Konstanten)', function () {
    FoodAlchemistTerminologyAlias::create(['team_id' => null, 'members' => ['savoy', 'wirsing']]);

    $phrases = (new TerminologyService())->aliasPhrasesFor('Savoy');

    expect($phrases)->toContain('wirsing');
});

it('DB-Anti-Marker wirkt additiv in isAntiMarker (token-begrenzt)', function () {
    FoodAlchemistTerminologyAntiMarker::create(['team_id' => null, 'trigger_token' => 'limette', 'forbid_token' => 'zitrone']);

    $svc = new TerminologyService();

    expect($svc->isAntiMarker('Limette', 'Zitrone, frisch'))->toBeTrue()
        ->and($svc->isAntiMarker('Limette', 'Limette, frisch'))->toBeFalse();
});

it('terminology.POST legt Alias + Anti-Marker an, LIST zeigt sie', function () {
    $ctx = new ToolContext($this->makeUser($this->rootTeam), $this->rootTeam);

    $a = (new TerminologyPostTool())->execute(['kind' => 'alias', 'members' => ['erdapfel', 'kartoffel', 'grundbirne']], $ctx);
    $m = (new TerminologyPostTool())->execute(['kind' => 'anti_marker', 'trigger' => 'brie', 'forbid' => 'bries', 'unless' => 'bries'], $ctx);
    expect($a->success)->toBeTrue()->and($m->success)->toBeTrue();

    // global geschrieben (team_id NULL = Master)
    expect(FoodAlchemistTerminologyAlias::whereNull('team_id')->count())->toBe(1);

    $list = (new TerminologyListTool())->execute([], $ctx);
    expect($list->success)->toBeTrue()
        ->and(collect($list->data['aliases'])->pluck('id'))->toContain($a->data['id'])
        ->and(collect($list->data['anti_markers'])->pluck('id'))->toContain($m->data['id']);
});

it('terminology.POST lehnt unvollständige Einträge ab', function () {
    $ctx = new ToolContext($this->makeUser($this->rootTeam), $this->rootTeam);

    expect((new TerminologyPostTool())->execute(['kind' => 'alias', 'members' => ['nur-eins']], $ctx)->success)->toBeFalse()
        ->and((new TerminologyPostTool())->execute(['kind' => 'anti_marker', 'trigger' => 'x'], $ctx)->success)->toBeFalse();
});

it('ein DB-Alias fließt bis in die matchIngredient-ENTSCHEIDUNG (Lernschleife-Payoff)', function () {
    FoodAlchemistTerminologyAlias::create(['team_id' => null, 'members' => ['savoy', 'wirsing']]);
    $gp = FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'gp_key' => 'e7b|wirsing', 'name' => 'Wirsing, frisch',
        'status' => 'approved', 'is_platzhalter' => false,
    ]);

    $m = app(IngredientMatchService::class)->matchIngredient($this->rootTeam, 'Savoy');

    expect($m['target'])->toBe('gp')
        ->and($m['gp_id'])->toBe($gp->id);
});

// ── E7-c: Lernschleife-UI an der ReviewQueue ────────────────────────────────────

it('ReviewQueue lernt einen Alias (Lernschleife-UI) und er wirkt sofort', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    Livewire::test(ReviewQueue::class)
        ->set('termAlias', 'paradeiser, tomate, paradiesapfel')
        ->call('terminologieAlias')
        ->assertSet('termAlias', '');   // Input nach Erfolg geleert

    expect(FoodAlchemistTerminologyAlias::whereNull('team_id')->count())->toBe(1)
        ->and((new TerminologyService())->aliasPhrasesFor('Paradeiser'))->toContain('tomate');
});

it('ReviewQueue lernt einen Anti-Marker', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    Livewire::test(ReviewQueue::class)
        ->set('termTrigger', 'brie')->set('termForbid', 'bries')
        ->call('terminologieAntiMarker')
        ->assertSet('termTrigger', '');

    expect(FoodAlchemistTerminologyAntiMarker::whereNull('team_id')->count())->toBe(1)
        ->and((new TerminologyService())->isAntiMarker('Brie', 'Bries, frisch'))->toBeTrue();
});

it('ReviewQueue-Alias mit <2 Phrasen zeigt Fehler, legt nichts an', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));

    Livewire::test(ReviewQueue::class)
        ->set('termAlias', 'nur-eins')
        ->call('terminologieAlias')
        ->assertSet('fehler', 'Eine Alias-Gruppe braucht ≥2 verschiedene Phrasen.');

    expect(FoodAlchemistTerminologyAlias::count())->toBe(0);
});
