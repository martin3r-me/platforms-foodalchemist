<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\KnowledgeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 52 Runde D — Einordnung in Bloecken.
 *
 * 1.073 aktive Dossiers brauchen Art und Geltung. Einzeln waeren das 1.073 Aufrufe bzw.
 * Klicks — damit wird der Korpus-Umbau nie fertig.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->tool = app(ToolRegistry::class)->get('foodalchemist.knowledge.EINORDNEN');

    // Vokabular saeen, sonst prueft der Test die Pruefung nicht (Harness-Tabellen sind leer).
    DB::table('foodalchemist_dish_main_groups')->insert([
        'uuid' => (string) UuidV7::generate(), 'code' => 'HG', 'label' => 'Hauptgang',
        'is_inactive' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->dossier = fn (string $slug) => app(KnowledgeService::class)->create($this->rootTeam, [
        'title' => 'Dossier '.$slug, 'slug' => $slug, 'category' => 'cross_cutting',
        'content_md' => 'Inhalt.',
    ]);
});

it('ordnet mehrere Dossiers in EINEM Aufruf ein', function () {
    ($this->dossier)('a');
    ($this->dossier)('b');

    $res = $this->tool->execute(['eintraege' => [
        ['slug' => 'a', 'art' => 'fachwissen'],
        ['slug' => 'b', 'art' => 'referenz', 'geltung' => ['gang' => ['hg']]],
    ]], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['eingeordnet'])->toBe(2)
        ->and($res->data['fehlgeschlagen'])->toBe(0);
    expect(DB::table('foodalchemist_knowledge_documents')->where('slug', 'a')->value('art'))->toBe('fachwissen')
        ->and(json_decode(DB::table('foodalchemist_knowledge_documents')->where('slug', 'b')->value('geltung'), true))
        ->toBe(['gang' => ['hg']]);
});

it('★ ein fehlerhafter Eintrag kippt den Block NICHT — er wird einzeln gemeldet', function () {
    ($this->dossier)('gut');

    $res = $this->tool->execute(['eintraege' => [
        ['slug' => 'gut', 'art' => 'fachwissen'],
        ['slug' => 'gibtsnicht', 'art' => 'fachwissen'],
        ['slug' => 'gut', 'art' => 'fachwissen', 'geltung' => ['gang' => ['hauptgang']]],  // Klartext statt Code
    ]], $this->kontext);

    expect($res->data['eingeordnet'])->toBe(1)
        ->and($res->data['fehlgeschlagen'])->toBe(2)
        ->and($res->data['fehler'][0]['slug'])->toBe('gibtsnicht')
        ->and($res->data['fehler'][1]['grund'])->toContain('Unbekannter Wert');
    // Der gute Eintrag ist trotzdem verbucht.
    expect(DB::table('foodalchemist_knowledge_documents')->where('slug', 'gut')->value('art'))->toBe('fachwissen');
});

it('★ Trockenlauf prueft, schreibt aber nichts', function () {
    ($this->dossier)('trocken');

    $res = $this->tool->execute(['pruefen' => true, 'eintraege' => [
        ['slug' => 'trocken', 'art' => 'fachwissen', 'geltung' => ['gang' => ['hg']]],
        ['slug' => 'trocken', 'art' => 'fachwissen', 'geltung' => ['gang' => ['quatsch']]],
    ]], $this->kontext);

    expect($res->data['modus'])->toBe('trockenlauf')
        ->and($res->data['eingeordnet'])->toBe(1)
        ->and($res->data['fehlgeschlagen'])->toBe(1)
        // ★ nichts geschrieben — das ist der Sinn
        ->and(DB::table('foodalchemist_knowledge_documents')->where('slug', 'trocken')->value('art'))->toBeNull();
});

it('★ aktiviert nichts, auch wenn jemand active mitschickt', function () {
    ($this->dossier)('inaktiv');
    // Ausdruecklich stillgelegt — `KnowledgeService::create` legt AKTIV an, das
    // Inaktiv-Verhalten sitzt im MCP-Tool, nicht im Service.
    DB::table('foodalchemist_knowledge_documents')->where('slug', 'inaktiv')->update(['active' => 0]);

    $this->tool->execute(['eintraege' => [
        ['slug' => 'inaktiv', 'art' => 'fachwissen', 'active' => true],
    ]], $this->kontext);

    expect((bool) DB::table('foodalchemist_knowledge_documents')->where('slug', 'inaktiv')->value('active'))
        ->toBeFalse();
});

it('weist einen zu grossen Block ab, statt ihn zu fahren', function () {
    $eintraege = array_fill(0, 201, ['slug' => 'x', 'art' => 'fachwissen']);

    $res = $this->tool->execute(['eintraege' => $eintraege], $this->kontext);

    expect($res->success)->toBeFalse()
        ->and($res->error)->toContain('Bloecken');
});

it('meldet einen Eintrag ohne Einordnung, statt ihn still zu schlucken', function () {
    ($this->dossier)('leer');

    $res = $this->tool->execute(['eintraege' => [['slug' => 'leer']]], $this->kontext);

    expect($res->data['fehlgeschlagen'])->toBe(1)
        ->and($res->data['fehler'][0]['grund'])->toContain('nichts einzuordnen');
});
