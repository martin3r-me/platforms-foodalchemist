<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\KnowledgeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Briefing Zutaten-Bulk-Import, 2026-09-18: knowledge.ALIAS kannte bisher nur add/remove,
 * keinen Lese-Weg. `add` schluckt eine Kollision stillschweigend (Dossier wird angelegt, Alias
 * bleibt weg) — Anlass: "acerola" lag schon als Alias auf einem fremden Dossier.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->tool = app(ToolRegistry::class)->get('foodalchemist.knowledge.ALIAS');

    $doc = app(KnowledgeService::class)->create($this->rootTeam, [
        'title' => 'Obst & Beeren — Was ist drin', 'slug' => 'obst_beeren--was-ist-drin',
        'category' => 'cross_cutting', 'content_md' => 'Inhalt.',
    ]);
    app(KnowledgeService::class)->addAlias($this->rootTeam, $doc->slug, 'acerola');
});

it('meldet einen belegten Alias-Kandidaten mit Ziel-Dossier', function () {
    $res = $this->tool->execute(['action' => 'check', 'aliases' => ['acerola']], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['belegt'])->toBe(1)
        ->and($res->data['eintraege'][0]['belegt'])->toBeTrue()
        ->and($res->data['eintraege'][0]['slug'])->toBe('obst_beeren--was-ist-drin');
});

it('meldet einen freien Alias-Kandidaten korrekt als frei', function () {
    $res = $this->tool->execute(['action' => 'check', 'aliases' => ['passionsfrucht']], $this->kontext);

    expect($res->data['belegt'])->toBe(0)
        ->and($res->data['eintraege'][0]['belegt'])->toBeFalse()
        ->and($res->data['eintraege'][0]['slug'])->toBeNull();
});

it('prueft mehrere Kandidaten in einem Aufruf, gemischt belegt/frei', function () {
    $res = $this->tool->execute(['action' => 'check', 'aliases' => ['acerola', 'vanille', 'passionsfrucht']], $this->kontext);

    expect($res->data['geprueft'])->toBe(3)
        ->and($res->data['belegt'])->toBe(1);
});

it('normalisiert Kandidaten wie addAlias (Str::slug), damit belegt:false auch bei add stimmt', function () {
    $res = $this->tool->execute(['action' => 'check', 'aliases' => ['Acerola']], $this->kontext);

    expect($res->data['eintraege'][0]['alias_slug'])->toBe('acerola')
        ->and($res->data['eintraege'][0]['belegt'])->toBeTrue();
});

it('ist rein lesend — kein Team-Kontext-Fehler noetig, aber verlangt aliases[]', function () {
    $res = $this->tool->execute(['action' => 'check'], $this->kontext);

    expect($res->success)->toBeFalse()
        ->and($res->error)->toContain('aliases');
});
