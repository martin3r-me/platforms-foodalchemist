<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 19 E9.5 — MCP-Lockstep für den Kreativ-Modus: creative_mode(_default) in
 * foodbook_kapitel.PUT + foodbooks.POST/GET, inkl. Vokabular-Validierung + Tenancy.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->registry = app(ToolRegistry::class);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->foodbooks = app(FoodbookService::class);
});

it('E9.5: foodbooks.POST setzt creative_mode_default, GET spiegelt ihn', function () {
    $post = $this->registry->get('foodalchemist.foodbooks.POST')->execute([
        'label' => 'MCP-FB', 'creative_mode_default' => 'datenbank',
    ], $this->kontext);
    expect($post->success)->toBeTrue()
        ->and($post->data['foodbook']['creative_mode_default'])->toBe('datenbank');

    $get = $this->registry->get('foodalchemist.foodbook.GET')->execute([
        'id' => $post->data['foodbook']['id'],
    ], $this->kontext);
    expect($get->success)->toBeTrue()
        ->and($get->data['defaults']['creative_mode_default'])->toBe('datenbank');
});

it('E9.5: foodbooks.POST mit ungültigem creative_mode_default → VALIDATION_ERROR', function () {
    $res = $this->registry->get('foodalchemist.foodbooks.POST')->execute([
        'label' => 'MCP-FB', 'creative_mode_default' => 'quatsch',
    ], $this->kontext);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('VALIDATION_ERROR');
});

it('E9.5: foodbook_kapitel.PUT setzt creative_mode + validiert Vokabular', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['label' => 'FB']);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'K']);

    $ok = $this->registry->get('foodalchemist.foodbook_kapitel.PUT')->execute([
        'kapitel_id' => $kap->id, 'creative_mode' => 'voll_kreativ',
    ], $this->kontext);
    expect($ok->success)->toBeTrue()
        ->and($ok->data['kapitel']['creative_mode'])->toBe('voll_kreativ')
        ->and($kap->refresh()->creative_mode)->toBe('voll_kreativ');

    $bad = $this->registry->get('foodalchemist.foodbook_kapitel.PUT')->execute([
        'kapitel_id' => $kap->id, 'creative_mode' => 'unfug',
    ], $this->kontext);
    expect($bad->success)->toBeFalse()->and($bad->errorCode)->toBe('VALIDATION_ERROR');
});

it('E9.5: Tenancy — fremdes Kapitel (Kind A) ist für Root nicht editierbar (NOT_FOUND)', function () {
    $fbFremd = $this->foodbooks->create($this->childA, ['label' => 'Fremd']);
    $kapFremd = $this->foodbooks->addKapitel($this->childA, $fbFremd->id, ['title' => 'KF']);

    $res = $this->registry->get('foodalchemist.foodbook_kapitel.PUT')->execute([
        'kapitel_id' => $kapFremd->id, 'creative_mode' => 'hybrid',
    ], $this->kontext);
    expect($res->success)->toBeFalse()->and($res->errorCode)->toBe('NOT_FOUND')
        ->and($kapFremd->refresh()->creative_mode)->toBeNull(); // nichts geschrieben
});

it('E9.5: pairing_inspiration.GET liefert Nachbarn je Modus (search→Anker, geerdet vs. abstrakt)', function () {
    // Pairing-Seed: zander → rote_bete (klassisch), tragender GP „Rote Bete"
    $mkAnker = function (string $slug) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst(str_replace('_', ' ', $slug)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $z = $mkAnker('zander');
    $rb = $mkAnker('rote_bete');
    foreach ([[$z, $rb], [$rb, $z]] as [$x, $y]) {
        DB::table('foodalchemist_pairing_anchor_edges')->insert([
            'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $x, 'anchor_b_id' => $y,
            'type' => 'klassisch', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $gp = $this->makeGp($this->rootTeam, 'Rote Bete');
    $gp->update(['is_derivat' => false, 'is_platzhalter' => false, 'is_favorite' => true]);
    DB::table('foodalchemist_gp_anchor_mappings')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'gp_id' => $gp->id, 'anchor_id' => $rb, 'role' => 'kern', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $tool = $this->registry->get('foodalchemist.pairing_inspiration.GET');

    // geerdet (Default hybrid) via Freitext-search
    $g = $tool->execute(['search' => 'zander'], $this->kontext);
    expect($g->success)->toBeTrue();
    $insp = $g->data['pairing_inspiration'];
    $rbN = collect($insp['inspiration'][0]['nachbarn'])->firstWhere('slug', 'rote_bete');
    expect($insp['geerdet'])->toBeTrue()
        ->and(collect($rbN['gps'])->firstWhere('name', 'Rote Bete')['bucket'])->toBe('fuehren');

    // abstrakt via modus-Override
    $a = $tool->execute(['seeds' => ['zander'], 'modus' => 'voll_kreativ'], $this->kontext);
    expect($a->data['pairing_inspiration']['geerdet'])->toBeFalse()
        ->and($a->data['pairing_inspiration']['inspiration'][0]['nachbarn'][0])->not->toHaveKey('gps');

    // ohne search/seeds → VALIDATION_ERROR
    expect($tool->execute([], $this->kontext)->errorCode)->toBe('VALIDATION_ERROR');
});
