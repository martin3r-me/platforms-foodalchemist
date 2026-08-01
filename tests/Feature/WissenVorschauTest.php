<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Knowledge\Browser as WissenBrowser;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 28 / E15: Markdown-Vorschau im Wissens-Schirm.
 *
 * Zwei Dinge, die der Vorschau sonst im Weg stehen:
 *  1. Die Dokumente tragen einen YAML-Kopf. CommonMark kennt ihn nicht und würde `---` als
 *     Trennstrich und die Felder darunter als Absatz rendern — die Vorschau begann mit
 *     „typ: … zweck: …".
 *  2. Wissen kommt auch aus PDF-Destillaten und Importen. Rohes HTML aus solchen Quellen
 *     gehört nicht ungeprüft in die Seite.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam, 'Root User'));
});

it('rendert Markdown zu HTML', function () {
    $c = Livewire::test(WissenBrowser::class)
        ->set('form.content_md', "# Überschrift\n\nEin **fetter** Satz.\n\n- eins\n- zwei\n");

    $html = $c->instance()->inhaltGerendert();

    expect($html)->toContain('<h1>')->toContain('<strong>')->toContain('<ul>')->toContain('<li>');
});

it('schneidet den YAML-Kopf ab, statt ihn als Fließtext zu rendern', function () {
    $md = "---\ntyp: Cross_Cutting\nzweck: Single Source of Truth\n---\n\n# Titel\n\nText.\n";

    $c = Livewire::test(WissenBrowser::class)->set('form.content_md', $md);
    $html = $c->instance()->inhaltGerendert();

    expect($html)->toContain('<h1>')->toContain('Titel');
    expect($html)->not->toContain('zweck:');
    expect($html)->not->toContain('Single Source of Truth');
    // `---` würde sonst als Trennstrich am Anfang stehen
    expect(trim($html))->toStartWith('<h1>');
});

it('gibt die Kopf-Felder für die Metadaten-Zeile zurück', function () {
    $md = "---\ntyp: Cross_Cutting\nverwendbar_in_skills: [recipe_creator, flavor_lab]\nleer:\n---\n\nText.\n";

    $felder = Livewire::test(WissenBrowser::class)->set('form.content_md', $md)->instance()->frontmatter();

    expect($felder)->toHaveKey('typ', 'Cross_Cutting');
    expect($felder)->toHaveKey('verwendbar_in_skills', '[recipe_creator, flavor_lab]');
    expect($felder)->not->toHaveKey('leer');          // leere Werte sind keine Information
});

it('lässt rohes HTML aus importierten Dokumenten nicht durch', function () {
    $md = "# Titel\n\n<script>alert('x')</script>\n\n<img src=x onerror=alert(1)>\n";

    $html = Livewire::test(WissenBrowser::class)->set('form.content_md', $md)->instance()->inhaltGerendert();

    expect($html)->not->toContain('<script')->not->toContain('onerror');
});

it('ein leeres Dokument liefert leere Vorschau statt Fehler', function () {
    $c = Livewire::test(WissenBrowser::class)->set('form.content_md', '');

    expect($c->instance()->inhaltGerendert())->toBe('');
    expect($c->instance()->frontmatter())->toBe([]);
});

it('der Schirm trennt Liste, Text und Verwendung in drei Zonen', function () {
    $html = Livewire::test(WissenBrowser::class)->html();

    // Auf MARKER prüfen, nicht auf Komponenten-Namen: `<x-ui-page-sidebar>` ist im gerenderten
    // HTML wegkompiliert. `activity_knowledge` ist der Scope des rechten Panels und überlebt
    // als Alpine-Zustand.
    expect($html)->toContain('data-wissen-liste')->toContain('activity_knowledge');
});
