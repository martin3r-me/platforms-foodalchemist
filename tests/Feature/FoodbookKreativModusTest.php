<?php

use Platform\FoodAlchemist\Services\FoodbookService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 19 E9.1 — Kreativ-Modus-Kaskade (Kapitel → Foodbook → Default 'hybrid'),
 * Persistenz über FELDER/KAPITEL_FELDER und leitplanken()-Integration.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->foodbooks = app(FoodbookService::class);
});

it('E9.1: Default ohne jede Wahl = hybrid (quelle default)', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['label' => 'FB']);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Kapitel']);

    $res = $this->foodbooks->kreativModus($this->rootTeam, $kap);
    expect($res['modus'])->toBe('hybrid')
        ->and($res['quelle'])->toBe('default')
        ->and($res['optionen'])->toBe(['voll_kreativ', 'hybrid', 'datenbank']);
});

it('E9.1: Kapitel ohne eigene Wahl erbt Foodbook-Default', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['label' => 'FB', 'creative_mode_default' => 'datenbank']);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Kapitel']);

    $res = $this->foodbooks->kreativModus($this->rootTeam, $kap->refresh());
    expect($res['modus'])->toBe('datenbank')->and($res['quelle'])->toBe('foodbook');
});

it('E9.1: Kapitel-Override gewinnt über Foodbook-Default', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['label' => 'FB', 'creative_mode_default' => 'datenbank']);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Kapitel']);
    $this->foodbooks->updateKapitel($this->rootTeam, $kap->id, ['creative_mode' => 'voll_kreativ']);

    $res = $this->foodbooks->kreativModus($this->rootTeam, $kap->refresh());
    expect($res['modus'])->toBe('voll_kreativ')->and($res['quelle'])->toBe('kapitel');
});

it('E9.1: ungültiger Modus fällt weich auf den Default zurück (Vokabular-Pflicht)', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['label' => 'FB', 'creative_mode_default' => 'quatsch']);
    $kap = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'Kapitel']);
    // Kapitel-Freitext ebenfalls ungültig → keine Ebene greift → Default
    $kap->forceFill(['creative_mode' => 'unfug'])->save();

    $res = $this->foodbooks->kreativModus($this->rootTeam, $kap->refresh());
    expect($res['modus'])->toBe('hybrid')->and($res['quelle'])->toBe('default');
});

it('E9.1: leitplanken() trägt creative_mode + quelle mit Kapitelbezug', function () {
    $fb = $this->foodbooks->create($this->rootTeam, ['label' => 'FB', 'creative_mode_default' => 'datenbank']);
    $kapA = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'A']);
    $kapB = $this->foodbooks->addKapitel($this->rootTeam, $fb->id, ['title' => 'B']);
    $this->foodbooks->updateKapitel($this->rootTeam, $kapB->id, ['creative_mode' => 'voll_kreativ']);

    // ohne Kapitel → Foodbook-Default
    $lpFb = $this->foodbooks->leitplanken($this->rootTeam, $fb->refresh());
    expect($lpFb['creative_mode'])->toBe('datenbank')
        ->and($lpFb['quellen']['creative_mode'])->toBe('foodbook');

    // Kapitel A erbt, Kapitel B override
    $lpA = $this->foodbooks->leitplanken($this->rootTeam, $fb, null, $kapA->refresh());
    $lpB = $this->foodbooks->leitplanken($this->rootTeam, $fb, null, $kapB->refresh());
    expect($lpA['creative_mode'])->toBe('datenbank')
        ->and($lpB['creative_mode'])->toBe('voll_kreativ')
        ->and($lpB['quellen']['creative_mode'])->toBe('kapitel');
});
