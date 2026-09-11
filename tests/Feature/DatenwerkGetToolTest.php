<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\KnowledgeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 52/Grundsatz A — Datenwerke werden AUFGELÖST, nicht gesucht.
 *
 * Die Lücke: ein Agent konnte bisher nur `knowledge.SEARCH` rufen und bekam die Prosa eines
 * Mengen-Dossiers — eine Markdown-Tabelle, aus der er die Zahl selbst herauslesen musste,
 * und das auch nur, wenn die Suche das richtige Dossier nach oben brachte. Genau davon darf
 * ein verbindlicher Standard nicht abhängen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config()->set('foodalchemist.semantic_search.enabled', false);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->tool = app(ToolRegistry::class)->get('foodalchemist.datenwerk.GET');

    $this->werk = fn (string $slug, array $werte) => app(KnowledgeService::class)->create($this->rootTeam, [
        'title' => 'Mengen '.$slug, 'slug' => $slug, 'category' => 'cross_cutting',
        'art' => 'datenwerk', 'content_md' => 'Prosa, die hier NICHT die Antwort ist.',
        'datenwerte' => $werte,
    ]);
    $this->wert = fn (float $min, float $max, string $bezug = 'roh', array $geltung = ['gang' => ['hauptgang']]) => [
        'kennzahl' => 'portion.filet_rind.'.$bezug, 'min' => $min, 'max' => $max,
        'einheit' => 'g', 'bezug' => $bezug, 'quelle' => 'Testtabelle 1', 'geltung' => $geltung,
    ];
});

it('ist als read_only registriert — sonst darf das Sidebar-Mikrofon es nicht nutzen', function () {
    expect($this->tool->getName())->toBe('foodalchemist.datenwerk.GET')
        ->and($this->tool->getMetadata()['read_only'])->toBeTrue();
});

it('liefert den strukturierten Wert MIT Bezugsgröße statt der Prosa', function () {
    ($this->werk)('mengen-test', [($this->wert)(180, 220)]);

    $res = $this->tool->execute(['gang' => 'hauptgang'], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['treffer'])->toBe(1);
    $e = $res->data['ergebnisse'][0];
    expect($e['status'])->toBe('aufgeloest')
        ->and((float) $e['werte']['min'])->toBe(180.0)
        ->and((float) $e['werte']['max'])->toBe(220.0)
        ->and($e['werte']['einheit'])->toBe('g')
        // ★ Der Bezug ist der eigentliche Gewinn: „180 g" allein sagt nicht, ob roh oder
        //   gegart. Bei 30 % Garverlust sind das 80 g Unterschied pro Person im Einkauf.
        ->and($e['werte']['bezug'])->toBe('roh');
});

it('meldet ohne Geltungs-Achse eine Lücke statt einer plausiblen Zahl', function () {
    ($this->werk)('mengen-test2', [($this->wert)(180, 220)]);

    $res = $this->tool->execute([], $this->kontext);

    expect($res->data['treffer'])->toBe(0)
        ->and($res->data['luecken'])->not->toBeEmpty()
        ->and($res->data['hinweis'])->toContain('Zufallswert');
});

it('meldet widersprüchliche Quellen als Widerspruch — ohne stillen Gewinner', function () {
    ($this->werk)('mengen-a', [($this->wert)(180, 220)]);
    ($this->werk)('mengen-b', [($this->wert)(250, 300)]);

    $res = $this->tool->execute(['gang' => 'hauptgang'], $this->kontext);

    $e = $res->data['ergebnisse'][0];
    expect($e['status'])->toBe('widerspruch')
        ->and($e['werte'])->toBeNull()                 // kein Wert wird ausgewaehlt
        ->and($e['kandidaten'])->toHaveCount(2);       // beide bleiben sichtbar
});

it('antwortet auf eine nicht gepflegte Geltung mit einer Lücke, nicht mit dem naechstbesten Wert', function () {
    ($this->werk)('mengen-test3', [($this->wert)(180, 220)]);

    $res = $this->tool->execute(['gang' => 'dessert'], $this->kontext);

    expect($res->data['treffer'])->toBe(0)
        ->and($res->data['luecken'])->not->toBeEmpty();
});

it('filtert auf eine Kennzahl, auch als Teiltreffer', function () {
    ($this->werk)('mengen-test4', [($this->wert)(180, 220), [
        'kennzahl' => 'portion.lachs.roh', 'min' => 180, 'max' => 200, 'einheit' => 'g',
        'bezug' => 'roh', 'quelle' => 'Testtabelle 1', 'geltung' => ['gang' => ['hauptgang']],
    ]]);

    $alle = $this->tool->execute(['gang' => 'hauptgang'], $this->kontext);
    $nur = $this->tool->execute(['gang' => 'hauptgang', 'kennzahl' => 'lachs'], $this->kontext);

    expect($alle->data['treffer'])->toBe(2)
        ->and($nur->data['treffer'])->toBe(1)
        ->and($nur->data['ergebnisse'][0]['kennzahl'])->toBe('portion.lachs.roh');
});
