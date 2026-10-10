<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Spec 81 — MCP im Lockstep: rules.GET / PREVIEW / PUT werden ausgeführt, nicht nur registriert. */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    config(['platform-shell.admins' => [strtolower((string) $this->user->email)]]);   // Regeln = Plattform-Admin
    $this->actingAs($this->user);
    $this->tool = fn (string $n) => app(ToolRegistry::class)->get('foodalchemist.rules.' . $n);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
});

it('GET listet und liefert eine Regel mit Parametern und Versionen', function () {
    $liste = ($this->tool)('GET')->execute(['regelwerk' => 'gp'], $this->kontext);
    $eine = ($this->tool)('GET')->execute(['schluessel' => 'gp.7.1.gebinde'], $this->kontext);

    expect($liste->success)->toBeTrue()
        ->and(collect($liste->data['regeln'])->pluck('schluessel'))->toContain('gp.7.1.gebinde')
        ->and($eine->data['params']['tokens'])->toContain('Dose')
        ->and($eine->data['versionen'])->toHaveCount(1);
});

it('PREVIEW zeigt die Wirkung einer Änderung, ohne zu speichern', function () {
    $this->makeGp($this->rootTeam, 'Tomaten: konserviert, Eimer');

    $p = ($this->tool)('PREVIEW')->execute(['schluessel' => 'gp.7.1.gebinde',
        'params' => ['tokens' => ['Dose', 'Eimer'], 'grund' => 'Verpackungswort (§7.1).']], $this->kontext);

    expect($p->success)->toBeTrue()
        ->and($p->data['neu_betroffen'])->toBe(1)
        ->and(FoodAlchemistRule::where('schluessel', 'gp.7.1.gebinde')->value('version'))->toBe(1);
});

it('PUT: aktive Regel ist per MCP gesperrt (Inaktiv-Falle), inaktive bekommt eine neue Fassung — immer AUS', function () {
    $r = FoodAlchemistRule::where('schluessel', 'gp.7.1.gebinde')->firstOrFail();
    expect($r->aktiv)->toBeTrue();

    $gesperrt = ($this->tool)('PUT')->execute(['schluessel' => 'gp.7.1.gebinde',
        'params' => ['tokens' => ['Dose', 'Eimer', 'Kiste'], 'grund' => 'Verpackungswort (§7.1).']], $this->kontext);
    expect($gesperrt->success)->toBeFalse()
        ->and($gesperrt->errorCode)->toBe('RULE_ACTIVE')
        ->and($r->fresh()->version)->toBe(1)->and($r->fresh()->aktiv)->toBeTrue();   // bleibt an und unverändert

    app(\Platform\FoodAlchemist\Services\Regeln\RegelService::class)->setzeAktiv($r->id, false);
    $ok = ($this->tool)('PUT')->execute(['schluessel' => 'gp.7.1.gebinde',
        'params' => ['tokens' => ['Dose', 'Eimer', 'Kiste'], 'grund' => 'Verpackungswort (§7.1).']], $this->kontext);
    expect($ok->success)->toBeTrue()
        ->and($r->fresh()->version)->toBe(3)->and($r->fresh()->aktiv)->toBeFalse();

    $falsch = ($this->tool)('PUT')->execute(['schluessel' => 'gp.7.1.gebinde', 'params' => ['tokens' => []]], $this->kontext);
    expect($falsch->success)->toBeFalse();
});

it('Nur der Plattform-Administrator liest und schreibt Regeln per MCP', function () {
    $teamAdmin = new ToolContext($this->makeUser($this->rootTeam, 'Team-Admin'), $this->rootTeam);

    expect(($this->tool)('PUT')->execute(['schluessel' => 'gp.10.generik', 'titel' => 'x'], $teamAdmin)->success)->toBeFalse()
        ->and(($this->tool)('GET')->execute([], $teamAdmin)->success)->toBeFalse();
});
