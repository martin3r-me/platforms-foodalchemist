<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Platform\FoodAlchemist\Jobs\GenerateRecipeJob;
use Platform\FoodAlchemist\Livewire\Planung\Index as PlanungIndex;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Services\PlanningSessionService;
use Platform\FoodAlchemist\Services\RecipeGenerationContextService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Foodpairing-Composer (2026-08-22):
 *  - C-b: der Anker-Graph liefert Harmonie-Stärke (axis/level) → Partner tragen ●●● / ●●.
 *  - B2: seed_anker aus dem Composer wird zum VERBINDLICHEN pairing_vorgabe-Kontext-Key
 *        (getrennt vom weichen pairing-Angebot); ohne Seeds fehlt der Key (byte-identisch).
 *  - B1: der Composer-Button dispatcht GenerateRecipeJob mit parameter.seed_anker, aber die
 *        Anker landen NICHT in den persistierten generation_params (kein Fan-out-Leak, R5).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->mkAnker = function (string $slug): int {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };

    // Harmonie-Kante bidirektional. axis='harmony' + level ist Pflicht fürs Stärke-Symbol (C-b).
    $this->mkHarmonie = function (int $a, int $b, int $level): void {
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            DB::table('foodalchemist_pairing_anchor_edges')->insert([
                'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $x, 'anchor_b_id' => $y,
                'type' => 'aroma', 'axis' => 'harmony', 'level' => $level,
                'weight' => $level === 3 ? 1.0 : 0.9, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    };
});

it('C-b: neighborsForName liefert Harmonie-Achse + Level je Partner', function () {
    $tomate = ($this->mkAnker)('tomate');
    $basilikum = ($this->mkAnker)('basilikum');
    ($this->mkHarmonie)($tomate, $basilikum, 3);

    $res = app(PairingService::class)->neighborsForName('tomate');
    expect($res['anker'])->not->toBeNull()
        ->and($res['partner'])->not->toBeEmpty();
    $p = $res['partner'][0];
    expect($p->axis)->toBe('harmony')
        ->and((int) $p->level)->toBe(3);
});

it('C-b: der FLAVOR-PAIRING-Block rahmt die Harmonie mit ●●● / ●●', function () {
    $tomate = ($this->mkAnker)('tomate');
    $basilikum = ($this->mkAnker)('basilikum');
    $mozzarella = ($this->mkAnker)('mozzarella');
    ($this->mkHarmonie)($tomate, $basilikum, 3);
    ($this->mkHarmonie)($tomate, $mozzarella, 2);
    // pairing:discovery-Routing wird per Import geseedet (nicht per Migration) → im Test setzen.
    DB::table('foodalchemist_knowledge_routings')->insertOrIgnore([
        'feature' => 'ai_generate_recipe', 'category' => 'pairing', 'mode' => 'discovery',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $ctx = app(KnowledgeContextService::class)
        ->contextFor('ai_generate_recipe', 'Tomate', null, [], ['rezept_typ' => 'basisrezept']);

    expect($ctx['block'])->toContain('FLAVOR-PAIRING')
        ->and($ctx['block'])->toContain('●●●')
        ->and($ctx['block'])->toContain('●●');
});

it('B2: seed_anker erzeugt den verbindlichen pairing_vorgabe-Key inkl. Harmonie-Palette', function () {
    $tomate = ($this->mkAnker)('tomate');
    $basilikum = ($this->mkAnker)('basilikum');
    ($this->mkHarmonie)($tomate, $basilikum, 3);

    $out = app(RecipeGenerationContextService::class)
        ->build($this->rootTeam, 'Sommerliche Kreation', ['seed_anker' => ['tomate']], false);

    expect($out['prompt'])->toHaveKey('pairing_vorgabe');
    $lv = $out['prompt']['pairing_vorgabe'];
    expect($lv['rolle'])->toBe('verbindliche_leit_aromen')
        ->and($lv['leit_aromen'][0]['aroma'])->toBe('Tomate')
        ->and($lv['leit_aromen'][0]['palette'])->not->toBeEmpty()
        ->and($lv['leit_aromen'][0]['palette'][0])->toContain('●●●');
    // Kontext-Inspektor spiegelt die Vorgabe-Anker.
    expect($out['kontext']['pairing_vorgabe'])->toBe(['Tomate']);
});

it('B2: ohne seed_anker fehlt der pairing_vorgabe-Key (byte-identisch)', function () {
    ($this->mkAnker)('tomate');

    $out = app(RecipeGenerationContextService::class)
        ->build($this->rootTeam, 'Sommerliche Kreation', [], false);

    expect($out['prompt'])->not->toHaveKey('pairing_vorgabe')
        ->and($out['kontext']['pairing_vorgabe'])->toBe([]);
});

it('B1: composerGeneriere dispatcht GenerateRecipeJob mit seed_anker — NICHT in generation_params', function () {
    Queue::fake();
    $tomate = ($this->mkAnker)('tomate');
    $session = app(PlanningSessionService::class)->create($this->rootTeam, ['title' => 'Composer', 'brief' => '']);

    Livewire::test(PlanungIndex::class)
        ->call('oeffne', $session->id)
        ->set('composerAnker', [['id' => $tomate, 'slug' => 'tomate', 'label' => 'Tomate']])
        ->call('composerGeneriere', 'rezept')
        ->assertSet('laeuft', true)
        ->assertNoRedirect();

    Queue::assertPushed(GenerateRecipeJob::class, fn ($job) => $job->vkModus === false
        && ($job->parameter['seed_anker'] ?? null) === ['tomate']);

    // Fan-out-Isolation (R5): die Seed-Anker dürfen NICHT in die persistierten generation_params.
    $gp = $session->refresh()->generation_params ?? [];
    expect($gp)->not->toHaveKey('seed_anker');
});

it('Grounding: seed_anker + nur_bestand baut den Kontext ohne Wurf', function () {
    ($this->mkAnker)('tomate');

    $out = app(RecipeGenerationContextService::class)->build(
        $this->rootTeam, 'Kreation', ['seed_anker' => ['tomate'], 'bestand' => 'nur_bestand'], false,
    );

    expect($out['prompt'])->toHaveKey('pairing_vorgabe');
});
