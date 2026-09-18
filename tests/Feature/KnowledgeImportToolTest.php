<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\KnowledgeService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Briefing Zutaten-Bulk-Import, 2026-09-18: 11.966 Zutaten-Dossiers, knowledge.POST (ein
 * Dokument je Aufruf) ist dafuer nicht vertretbar. knowledge.IMPORT ist der Massen-Einstieg,
 * idempotent ueber den Slug per Content-Hash.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->kontext = new ToolContext($this->user, $this->rootTeam);
    $this->tool = app(ToolRegistry::class)->get('foodalchemist.knowledge.IMPORT');
});

it('legt neue Eintraege an, standardmaessig inaktiv', function () {
    $res = $this->tool->execute(['eintraege' => [
        ['slug' => 'zutat.acerola--verwendung', 'title' => 'Acerola Verwendung', 'category' => 'cross_cutting',
            'art' => 'fachwissen', 'content_md' => 'Acerola Verwendung Inhalt.'],
    ]], $this->kontext);

    expect($res->success)->toBeTrue()
        ->and($res->data['angelegt'])->toBe(1)
        ->and($res->data['eintraege'][0]['status'])->toBe('angelegt');
    $doc = DB::table('foodalchemist_knowledge_documents')->where('slug', 'zutat.acerola--verwendung')->first();
    expect((bool) $doc->active)->toBeFalse()
        ->and($doc->version)->toBe(1)
        ->and($doc->art)->toBe('fachwissen');
});

it('ist idempotent ueber den Slug: gleicher Inhalt -> unveraendert, kein Versionsbump', function () {
    $eintrag = ['slug' => 'zutat.acerola--verwendung', 'title' => 'Acerola Verwendung', 'category' => 'cross_cutting',
        'art' => 'fachwissen', 'content_md' => 'Acerola Verwendung Inhalt.'];

    $erster = $this->tool->execute(['eintraege' => [$eintrag]], $this->kontext);
    $zweiter = $this->tool->execute(['eintraege' => [$eintrag]], $this->kontext);

    expect($erster->data['eintraege'][0]['status'])->toBe('angelegt')
        ->and($zweiter->data['eintraege'][0]['status'])->toBe('unveraendert')
        ->and($zweiter->data['unveraendert'])->toBe(1);
    expect(DB::table('foodalchemist_knowledge_documents')->where('slug', 'zutat.acerola--verwendung')->value('version'))->toBe(1);
});

it('aktualisiert bei geaendertem Inhalt und erhoeht die Version', function () {
    $this->tool->execute(['eintraege' => [
        ['slug' => 'zutat.acerola--verwendung', 'title' => 'Acerola Verwendung', 'category' => 'cross_cutting',
            'art' => 'fachwissen', 'content_md' => 'Erste Fassung.'],
    ]], $this->kontext);

    $res = $this->tool->execute(['eintraege' => [
        ['slug' => 'zutat.acerola--verwendung', 'title' => 'Acerola Verwendung', 'category' => 'cross_cutting',
            'art' => 'fachwissen', 'content_md' => 'Zweite, geaenderte Fassung.'],
    ]], $this->kontext);

    expect($res->data['eintraege'][0]['status'])->toBe('aktualisiert')
        ->and($res->data['eintraege'][0]['version'])->toBe(2);
    expect(DB::table('foodalchemist_knowledge_documents')->where('slug', 'zutat.acerola--verwendung')->value('content_md'))
        ->toBe('Zweite, geaenderte Fassung.');
});

it('★ ein abgelehnter Eintrag kippt den Block NICHT — er wird einzeln gemeldet', function () {
    $res = $this->tool->execute(['eintraege' => [
        ['slug' => 'zutat.gut--verwendung', 'title' => 'Gut', 'category' => 'cross_cutting',
            'art' => 'fachwissen', 'content_md' => 'Guter Inhalt.'],
        ['slug' => 'Ungueltiger Slug Mit Leerzeichen', 'title' => 'Schlecht', 'category' => 'cross_cutting',
            'art' => 'fachwissen', 'content_md' => 'Inhalt.'],
        ['slug' => 'zutat.schlecht--verwendung', 'title' => 'Schlecht', 'category' => 'unbekannte-kategorie',
            'art' => 'fachwissen', 'content_md' => 'Inhalt.'],
    ]], $this->kontext);

    expect($res->data['angelegt'])->toBe(1)
        ->and($res->data['abgelehnt'])->toBe(2)
        ->and($res->data['eintraege'][1]['status'])->toBe('abgelehnt')
        ->and($res->data['eintraege'][1]['grund'])->toContain('Slug-Muster')
        ->and($res->data['eintraege'][2]['status'])->toBe('abgelehnt');
    expect(DB::table('foodalchemist_knowledge_documents')->where('slug', 'zutat.gut--verwendung')->exists())->toBeTrue();
});

it('lehnt content_md ueber 4.000 Zeichen ab', function () {
    $res = $this->tool->execute(['eintraege' => [
        ['slug' => 'zutat.zu_lang--verwendung', 'title' => 'Zu lang', 'category' => 'cross_cutting',
            'art' => 'fachwissen', 'content_md' => str_repeat('x', 4001)],
    ]], $this->kontext);

    expect($res->data['eintraege'][0]['status'])->toBe('abgelehnt')
        ->and($res->data['eintraege'][0]['grund'])->toContain('4.000 Zeichen');
});

/**
 * Sonderzeichen-Test (Briefing Punkt 8): Gedankenstrich, typografische Anfuehrungszeichen,
 * Markdown-Bold und Backticks sind im FA-POST-Pfad unproblematisch (kein eigener Validator) —
 * hier fuer den IMPORT-Pfad explizit bestaetigt.
 */
it('nimmt Gedankenstriche, Anfuehrungszeichen, Bold und Backticks anstandslos an', function () {
    $content = 'Ein Text — mit „typografischen" Anfuehrungszeichen, **fettem** Markdown und `Backticks`.';

    $res = $this->tool->execute(['eintraege' => [
        ['slug' => 'zutat.sonderzeichen--verwendung', 'title' => 'Sonderzeichen-Test', 'category' => 'cross_cutting',
            'art' => 'fachwissen', 'content_md' => $content],
    ]], $this->kontext);

    expect($res->data['eintraege'][0]['status'])->toBe('angelegt');
    expect(DB::table('foodalchemist_knowledge_documents')->where('slug', 'zutat.sonderzeichen--verwendung')->value('content_md'))
        ->toBe($content);
});

it('nimmt frontmatter entgegen, speichert es aber nicht als eigenes Feld', function () {
    $res = $this->tool->execute(['eintraege' => [
        ['slug' => 'zutat.mit_frontmatter--verwendung', 'title' => 'Mit Frontmatter', 'category' => 'cross_cutting',
            'art' => 'fachwissen', 'content_md' => 'Inhalt.', 'frontmatter' => ['anker_slug' => 'acerola_14']],
    ]], $this->kontext);

    expect($res->data['eintraege'][0]['status'])->toBe('angelegt');
});

it('legt active=true direkt aktiv an und stoesst das Embedding an (No-op ohne Provider)', function () {
    $res = $this->tool->execute(['eintraege' => [
        ['slug' => 'zutat.aktiv--verwendung', 'title' => 'Aktiv', 'category' => 'cross_cutting',
            'art' => 'fachwissen', 'content_md' => 'Inhalt.', 'active' => true],
    ]], $this->kontext);

    expect($res->success)->toBeTrue();
    expect((bool) DB::table('foodalchemist_knowledge_documents')->where('slug', 'zutat.aktiv--verwendung')->value('active'))
        ->toBeTrue();
});

it('weist einen zu grossen Block ab, statt ihn zu fahren', function () {
    $eintraege = array_fill(0, KnowledgeService::IMPORT_MAX + 1, [
        'slug' => 'x', 'title' => 'x', 'category' => 'cross_cutting', 'art' => 'fachwissen', 'content_md' => 'x',
    ]);

    $res = $this->tool->execute(['eintraege' => $eintraege], $this->kontext);

    expect($res->success)->toBeFalse()
        ->and($res->error)->toContain('Bloecken');
});
