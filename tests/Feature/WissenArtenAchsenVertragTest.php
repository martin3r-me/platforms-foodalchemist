<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Livewire\Knowledge\Browser;
use Platform\FoodAlchemist\Livewire\Settings\Wissenssteuerung;
use Platform\FoodAlchemist\Services\Ai\KnowledgeContextService;
use Platform\FoodAlchemist\Services\Knowledge\DatenwerkResolver;
use Platform\FoodAlchemist\Services\Knowledge\WissensGeltung;
use Platform\FoodAlchemist\Services\KnowledgeRoutingService;
use Platform\FoodAlchemist\Services\KnowledgeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    config()->set('foodalchemist.semantic_search.enabled', false);
    $this->doc = fn ($slug, $art, $extra = []) => app(KnowledgeService::class)->create($this->rootTeam, array_replace([
        'title' => 'Karotte '.$slug, 'slug' => $slug, 'category' => 'cross_cutting', 'art' => $art,
        'content_md' => 'Vollständiges Wissen ohne Mengenannahmen.',
    ], $extra));
    $this->value = fn ($min = 100) => ['kennzahl' => 'hauptkomponente', 'min' => $min, 'max' => $min,
        'einheit' => 'g', 'bezug' => 'verzehrfertig pro Portion', 'quelle' => 'Testquelle, Tabelle 1', 'geltung' => []];
});

it('verknüpft Achsen mit UND und Alternativen mit ODER und erfindet fehlende Parameter nicht', function () {
    $conditions = WissensGeltung::normalisieren(['gang' => 'hauptgang', 'niveau' => 'klassisch, gehoben']);
    expect(WissensGeltung::passt($conditions, ['gang' => 'hauptgang', 'level' => 'gehoben']))->toBeTrue()
        ->and(WissensGeltung::passt($conditions, ['gang' => 'vorspeise', 'level' => 'gehoben']))->toBeFalse()
        ->and(WissensGeltung::passt($conditions, ['gang' => 'hauptgang']))->toBeFalse();
});

it('sucht nach Art über Kategorien hinweg und hält Regeln Datenwerke und Abläufe aus Discovery', function () {
    ($this->doc)('fachwissen', 'fachwissen');
    ($this->doc)('referenz', 'referenz');
    ($this->doc)('regel', 'regel');
    ($this->doc)('datenwerk', 'datenwerk');
    ($this->doc)('ablauf', 'ablauf');
    app(KnowledgeRoutingService::class)->setArt('test.art', 'fachwissen', 'discovery', 10);
    $context = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'test.art', 'Karotte');
    expect($context['files_used'])->toBe(['fachwissen@v1']);
    expect(app(KnowledgeContextService::class)->searchDocuments($this->rootTeam, 'Karotte'))->toHaveCount(5);
});

it('prüft Geltung vor Top-K und verwendet keine passende Suche aus falschem Kontext', function () {
    ($this->doc)('a-falsch', 'fachwissen', ['geltung' => ['gang' => ['vorspeise']]]);
    ($this->doc)('z-richtig', 'fachwissen', ['geltung' => ['gang' => ['hauptgang']]]);
    app(KnowledgeRoutingService::class)->setArt('test.art', 'fachwissen', 'discovery', 1);
    $context = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'test.art', 'Karotte', null, [], ['gang' => 'hauptgang']);
    expect($context['files_used'])->toBe(['z-richtig@v1']);
});

it('löst Datenwerke ohne Suchwort auf und übermittelt Wert Einheit Bezug und Version statt Prosa', function () {
    ($this->doc)('portion', 'datenwerk', ['geltung' => ['gang' => ['hauptgang'], 'komponentenrolle' => ['hauptkomponente']],
        'datenwerte' => [($this->value)()], 'content_md' => 'Diese Prosa darf nicht verwendet werden.']);
    app(KnowledgeRoutingService::class)->setArt('test.data', 'datenwerk', 'resolve');
    $context = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'test.data', 'Keine Textübereinstimmung', null, [],
        ['gang' => 'hauptgang', 'komponentenrolle' => 'hauptkomponente']);
    expect($context['datenwerk']['ergebnisse'][0]['status'])->toBe('aufgeloest')
        ->and($context['block'])->toContain('verzehrfertig pro Portion', 'Testquelle', 'portion@v1')
        ->not->toContain('Diese Prosa')
        ->and($context['files_used'])->toBe(['portion@v1']);
});

it('meldet fehlende Daten und widersprüchliche Werte ohne stillen Gewinner', function () {
    $resolver = app(DatenwerkResolver::class);
    $base = DB::table('foodalchemist_knowledge_documents');
    expect($resolver->resolve($base, [])['luecken'])->not->toBeEmpty();
    foreach (['eins' => 100, 'zwei' => 150] as $slug => $min) ($this->doc)($slug, 'datenwerk', [
        'geltung' => ['gang' => ['hauptgang']], 'datenwerte' => [($this->value)($min)],
    ]);
    $result = $resolver->resolve($base, ['gang' => 'hauptgang']);
    expect($result['ergebnisse'][0]['status'])->toBe('widerspruch')
        ->and($result['ergebnisse'][0]['werte'])->toBeNull()
        ->and($result['ergebnisse'][0]['kandidaten'])->toHaveCount(2);
});

it('weist Daten ohne Bezugsgröße und ungeeignete Art-Routing-Modi zurück', function () {
    $row = ($this->value)(); unset($row['bezug']);
    expect(fn () => ($this->doc)('kaputt', 'datenwerk', ['geltung' => ['gang' => ['hauptgang']], 'datenwerte' => [$row]]))->toThrow(RuntimeException::class, 'bezug');
    expect(fn () => app(KnowledgeRoutingService::class)->setArt('test.art', 'datenwerk', 'discovery'))->toThrow(InvalidArgumentException::class);
    expect(fn () => app(KnowledgeRoutingService::class)->setArt('test.art', 'fachwissen', 'resolve'))->toThrow(InvalidArgumentException::class);
});

it('speichert Einordnung über denselben Vertrag in UI und MCP und versioniert Änderungen', function () {
    $doc = ($this->doc)('ui-doc', 'datenwerk');
    Livewire::test(Browser::class)->call('select', $doc->id)
        ->set('form.geltung.gang', 'hauptgang')->set('form.datenwerte', [($this->value)()])
        ->call('save')->assertSet('fehler', null)->assertSee('Strukturierte Datenwerte');
    $get = app(ToolRegistry::class)->get('foodalchemist.knowledge.GET')->execute(['slug' => 'ui-doc'], new ToolContext($this->user, $this->rootTeam));
    expect($get->data['geltung'])->toBe(['gang' => ['hauptgang']])->and($get->data['version'])->toBe(2)
        ->and($get->data['datenwerte'][0]['bezug'])->toBe('verzehrfertig pro Portion');
});

it('bietet Arten-Routing in der Steuerungsoberfläche an', function () {
    Livewire::test(Wissenssteuerung::class)->set('artForm.feature', 'recipe.generator')
        ->set('artForm.art', 'datenwerk')->set('artForm.mode', 'resolve')->call('saveArt')->assertSet('fehler', null);
    expect(app(KnowledgeRoutingService::class)->list('recipe.generator'))->toContainEqual([
        'feature' => 'recipe.generator', 'category' => '', 'art' => 'datenwerk', 'mode' => 'resolve', 'max_docs' => 3, 'max_chars_per_doc' => null,
    ]);
});

it('unterstützt jede ausgebaute Achse ohne Suchrang', function ($axis) {
    ($this->doc)('standard', 'datenwerk', ['geltung' => [$axis => ['passend']], 'datenwerte' => [($this->value)()]]);
    app(KnowledgeRoutingService::class)->setArt('test.axes', 'datenwerk', 'resolve');
    $context = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'test.axes', '', null, [], [$axis => 'passend']);
    expect($context['files_used'])->toBe(['standard@v1']);
})->with(array_keys(WissensGeltung::ACHSEN));

it('behält eine Datenlücke auch unter einem zu kleinen Budget sichtbar', function () {
    app(KnowledgeRoutingService::class)->setArt('test.gap', 'datenwerk', 'resolve');
    config()->set('foodalchemist.ai.knowledge_budget', ['test.gap' => 1]);
    expect(fn () => app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'test.gap', ''))
        ->toThrow(\Platform\FoodAlchemist\Services\Ai\KnowledgeBudgetExceeded::class);

});

it('hält nicht eingeordneten Bestand bei Arten-Routing über den Kategorie-Fallback verfügbar', function () {
    ($this->doc)('altbestand', null);
    ($this->doc)('referenz', 'referenz');
    app(KnowledgeRoutingService::class)->set('test.legacy', 'cross_cutting', 'discovery', 10);
    app(KnowledgeRoutingService::class)->setArt('test.legacy', 'referenz', 'none');
    $context = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'test.legacy', 'Karotte');
    expect($context['files_used'])->toBe(['altbestand@v1']);
});

it('liefert mehrere Werte eines Datenwerks mit genau einer Quellenangabe im Audit', function () {
    $two = ($this->value)(200); $two['kennzahl'] = 'beilage';
    ($this->doc)('portionen', 'datenwerk', ['geltung' => ['gang' => ['hauptgang']], 'datenwerte' => [($this->value)(), $two]]);
    app(KnowledgeRoutingService::class)->setArt('test.two', 'datenwerk', 'resolve');
    $context = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'test.two', '', null, [], ['gang' => 'hauptgang']);
    expect($context['files_used'])->toBe(['portionen@v1'])->and($context['datenwerk']['ergebnisse'])->toHaveCount(2);
});

it('macht strukturierte Dossiers bereits beim Anlegen über die Oberfläche pflegbar', function () {
    $embedding = Mockery::mock(\Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService::class);
    $embedding->shouldReceive('queueDocument')->once()->withArgs(fn ($doc) => $doc->slug === 'neues-datenwerk');
    $this->app->instance(\Platform\FoodAlchemist\Services\Ai\KnowledgeEmbeddingService::class, $embedding);
    Livewire::test(Browser::class)->call('neu')->set('form.title', 'Neues Datenwerk')
        ->set('form.category', 'cross_cutting')->set('form.art', 'datenwerk')
        ->set('form.geltung.gang', 'hauptgang')->set('form.datenwerte', [($this->value)()])
        ->assertSee('Strukturierte Datenwerte')->call('save')->assertSet('fehler', null)->assertSet('creating', false);
    expect(DB::table('foodalchemist_knowledge_documents')->where('slug', 'neues-datenwerk')->value('datenwerte'))->toContain('Testquelle');
});

it('schützt globale Arten-Routings und fremde Datenwerk-Inhalte vor Änderungen durch ein Kind-Team', function () {
    $doc = ($this->doc)('geschuetzt', 'datenwerk');
    $childUser = $this->makeUser($this->childA);
    $context = new ToolContext($childUser, $this->childA);
    $put = app(ToolRegistry::class)->get('foodalchemist.knowledge.PUT');
    $put->execute(['slug' => 'geschuetzt', 'geltung' => ['gang' => ['hauptgang']]], $context);
    app(ToolRegistry::class)->get('foodalchemist.knowledge_routings.PUT')->execute([
        'feature' => 'test.verboten', 'art' => 'datenwerk', 'mode' => 'resolve',
    ], $context);
    expect(DB::table('foodalchemist_knowledge_documents')->where('id', $doc->id)->value('version'))->toBe(1)
        ->and(DB::table('foodalchemist_knowledge_routings')->where('feature', 'test.verboten')->exists())->toBeFalse();
});

it('speichert und liest strukturierte Daten über MCP ohne eigene Schreiblogik', function () {
    $context = new ToolContext($this->user, $this->rootTeam);
    app(ToolRegistry::class)->get('foodalchemist.knowledge.POST')->execute([
        'title' => 'MCP Daten', 'slug' => 'mcp-daten', 'category' => 'cross_cutting', 'art' => 'datenwerk',
        'geltung' => ['gang' => ['hauptgang']], 'datenwerte' => [($this->value)()],
    ], $context);
    app(ToolRegistry::class)->get('foodalchemist.knowledge.PUT')->execute([
        'slug' => 'mcp-daten', 'datenwerte' => [($this->value)(120)],
    ], $context);
    $result = app(ToolRegistry::class)->get('foodalchemist.knowledge.GET')->execute(['slug' => 'mcp-daten'], $context);
    expect($result->data['version'])->toBe(2)->and($result->data['datenwerte'][0]['min'])->toBe(120);
});

it('behält explizit über eine Achse gebundene Regeln vollständig neben dem Arten-Routing', function () {
    $content = str_repeat('Verbindliche Regel. ', 220);
    ($this->doc)('achsen-regel', 'regel', ['content_md' => $content]);
    config()->set('foodalchemist.ai.knowledge_axis_map', ['gang' => ['hauptgang' => ['achsen-regel']]]);
    app(KnowledgeRoutingService::class)->setArt('test.kanon-achse', 'fachwissen', 'none');
    $context = app(KnowledgeContextService::class)->contextFor($this->rootTeam, 'test.kanon-achse', '', null, [], ['gang' => 'hauptgang']);
    expect($context['files_used'])->toBe(['achsen-regel@v1'])->and($context['block'])->toContain($content)
        ->and($context['required_chars'])->toBeGreaterThan(mb_strlen($content));
});

it('verwendet bei wiederverwendetem Dienst die Geltung des aktuellen Auftrags', function () {
    ($this->doc)('hauptgang', 'fachwissen', ['geltung' => ['gang' => ['hauptgang']]]);
    ($this->doc)('vorspeise', 'fachwissen', ['geltung' => ['gang' => ['vorspeise']]]);
    app(KnowledgeRoutingService::class)->set('test.cache', 'cross_cutting', 'discovery', 10);
    $service = app(KnowledgeContextService::class);
    expect($service->contextFor($this->rootTeam, 'test.cache', 'Karotte', null, [], ['gang' => 'hauptgang'])['files_used'])->toBe(['hauptgang@v1']);
    expect($service->contextFor($this->rootTeam, 'test.cache', 'Karotte', null, [], ['gang' => 'vorspeise'])['files_used'])->toBe(['vorspeise@v1']);
});

/**
 * Spec 52/H2 — die Lücke, an der die Datenwerke sonst stumm bleiben.
 *
 * `contextFor()` liest die Achse als `$params[<achse>]`, und `WissensGeltung::passt()`
 * vergleicht gegen denselben Schlüssel. Aber `recipes.GENERATE` filtert die Eingabe gegen
 * eine Whitelist — stand eine Achse dort nicht drin, fiel sie weg, der Resolver sah keinen
 * Parameter und meldete eine LÜCKE. Gemessen am 2026-09-10: von neun Achsen kamen drei an.
 *
 * Das ist absichtlich ein VERTRAGS-Test gegen `WissensGeltung::ACHSEN` statt gegen eine
 * abgeschriebene Liste: eine neue Achse muss die MCP-Fläche mitziehen, sonst ist sie eine
 * Vorwärtsdeklaration, die niemandem auffällt.
 */
it('Spec 52/H2: jede Geltungs-Achse ist an der MCP-Fläche annehmbar — sonst löst kein Datenwerk auf', function () {
    $schema = app(ToolRegistry::class)->get('foodalchemist.recipes.GENERATE')->getSchema();
    $felder = array_keys($schema['properties'] ?? []);

    foreach (array_keys(WissensGeltung::ACHSEN) as $achse) {
        // `niveau` trägt historisch den Namen `level`; passt() löst den Alias selbst auf.
        expect($felder)->toContain($achse === 'niveau' ? 'level' : $achse);
    }
});

it('Spec 52/H2: eine Achse, die das Schema kennt, überlebt auch den Parameter-Filter', function () {
    $tool = app(ToolRegistry::class)->get('foodalchemist.recipes.GENERATE');

    // Der Filter ist privat — geprüft wird sein Ergebnis: der Aufbau, den der Generator
    // bekommt. Ohne Durchreichung stünde hier kein `gang`, und der Resolver bliebe blind.
    $spiegel = new ReflectionMethod($tool, 'durchreichSchluessel');
    $spiegel->setAccessible(true);
    $schluessel = $spiegel->invoke(null);

    foreach (array_keys(WissensGeltung::ACHSEN) as $achse) {
        expect($schluessel)->toContain($achse);
    }
    // Und die Pills dürfen dabei nicht verloren gehen.
    foreach (['convenience', 'frische', 'bestand', 'level', 'aroma', 'serviceform', 'kompositions_stil'] as $pill) {
        expect($schluessel)->toContain($pill);
    }
});
