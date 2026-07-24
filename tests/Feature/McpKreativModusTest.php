<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

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
