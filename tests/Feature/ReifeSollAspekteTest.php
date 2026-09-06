<?php

use Platform\FoodAlchemist\Services\AngebotService;
use Platform\FoodAlchemist\Services\ReifeService;
use Platform\FoodAlchemist\Services\SpeisekarteService;
use Platform\FoodAlchemist\Services\SpeiseplanService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 50 · E-3 — der Wächter über `sollAspekte()`.
 *
 * `sollAspekte()` ist eine zweite Liste neben `messe()`. Zweite Listen altern — genau daran
 * sind die Workflow-Docs seit Juli 2026 gescheitert. Deshalb prüft dieser Test die einzige
 * Invariante, die zählt: **jeder Code, den ein realer `messe()`-Lauf erzeugt, ist deklariert.**
 *
 * Umgekehrt gilt das bewusst NICHT: die Deklaration darf mehr kennen als ein einzelnes leeres
 * Artefakt zeigt (Ampel-Befunde, Coverage-Dimensionen, bedingte Aspekte). Ein Test, der
 * Gleichheit fordert, würde nur erzwingen, dass man für jeden Aspekt ein Fixture baut.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->svc = app(ReifeService::class);
});

it('jeder gemessene Lücken-Code ist als Soll-Aspekt deklariert', function () {
    // Je Kind ein frisch angelegtes, möglichst leeres Artefakt — das erzeugt die meisten Codes.
    $konzept = $this->makeConcept($this->rootTeam, 'Wächter-Konzept', ['status' => 'draft']);
    $this->makeConceptSlot($konzept, ['sales_recipe_id' => null]);
    $foodbook = $this->makeFoodbook($this->rootTeam, 'Wächter-Buch');
    $this->makeChapter($foodbook, ['title' => 'Leeres Kapitel']);
    $rezept = $this->makeRecipe($this->rootTeam, 'Wächter-Rezept');
    $gericht = $this->makeRecipe($this->rootTeam, 'Wächter-Gericht', ['is_sales_recipe' => true]);

    $faelle = [
        'recipe' => (int) $rezept->id,
        'gericht' => (int) $gericht->id,
        'concept' => (int) $konzept->id,
        'foodbook' => (int) $foodbook->id,
        'speisekarte' => (int) app(SpeisekarteService::class)->create($this->rootTeam, ['name' => 'Wächter-Karte'])->id,
        'speiseplan' => (int) app(SpeiseplanService::class)->create($this->rootTeam, ['name' => 'Wächter-Plan'])->id,
        'angebot' => (int) app(AngebotService::class)->create($this->rootTeam, ['name' => 'Wächter-Angebot'])->id,
    ];

    $undeklariert = [];
    foreach ($faelle as $kind => $id) {
        $reife = $this->svc->reife($this->rootTeam, $kind, $id);
        expect($reife)->not->toBeNull("reife($kind, $id) lieferte null");

        $deklariert = array_column($this->svc->sollAspekte($kind), 'code');
        foreach (array_column($reife['luecken'], 'code') as $code) {
            if (! in_array($code, $deklariert, true)) {
                $undeklariert[] = "$kind: $code";
            }
        }
    }

    expect($undeklariert)->toBe([], 'Nicht deklarierte Lücken-Codes: ' . implode(', ', $undeklariert));
});

it('jedes Kind kennt seine Soll-Aspekte, und jeder Aspekt ist vollständig beschrieben', function () {
    $schweren = ['hinweis', 'wichtig', 'blockiert'];

    foreach (ReifeService::KINDS as $kind) {
        $aspekte = $this->svc->sollAspekte($kind);
        expect($aspekte)->not->toBe([], "Kind $kind deklariert keine Aspekte");

        foreach ($aspekte as $a) {
            expect($a)->toHaveKey('code')->toHaveKey('schwere')->toHaveKey('wie')
                ->and($a['schwere'])->toBeIn($schweren, "$kind/{$a['code']}: unbekannte Schwere")
                ->and($a['code'])->toBeString()->not->toBe('');
        }
    }
});

it('kein Aspekt nennt ein Werkzeug, das es nicht gibt', function () {
    // Die Regel dahinter: lieber `wie: null` als ein erfundener Tool-Name. Ein Agent, der
    // einem nicht existierenden Tool folgt, verliert einen Zug und das Vertrauen in die Auskunft.
    $registry = app(\Platform\Core\Tools\ToolRegistry::class);
    $erfunden = [];

    foreach (ReifeService::KINDS as $kind) {
        foreach ($this->svc->sollAspekte($kind) as $a) {
            if ($a['wie'] !== null && $registry->get($a['wie']) === null) {
                $erfunden[] = "$kind/{$a['code']} → {$a['wie']}";
            }
        }
    }

    expect($erfunden)->toBe([], 'Aspekte mit nicht registriertem Tool: ' . implode(', ', $erfunden));
});

it('Aliasse liefern dieselben Aspekte wie ihr Kanon-Kind', function () {
    expect($this->svc->sollAspekte('paket'))->toBe($this->svc->sollAspekte('concept'))
        ->and($this->svc->sollAspekte('offer'))->toBe($this->svc->sollAspekte('angebot'))
        ->and($this->svc->sollAspekte('vk'))->toBe($this->svc->sollAspekte('gericht'));
});
