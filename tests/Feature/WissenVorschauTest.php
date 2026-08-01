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

it('lässt die erste H1 weg, wenn sie nur den Titel wiederholt', function () {
    $c = Livewire::test(WissenBrowser::class)
        ->set('form.title', 'Agentic Decision Matrix')
        ->set('form.content_md', "# Agentic Decision Matrix\n\nErster Absatz.\n\n## Kapitel\n");

    $html = $c->instance()->inhaltGerendert();

    expect($html)->not->toContain('<h1>');          // der Doppler ist weg …
    expect($html)->toContain('<h2>')->toContain('Erster Absatz.');   // … der Rest steht

    // Eine ABWEICHENDE H1 trägt Information und bleibt stehen.
    $c->set('form.content_md', "# Ganz anderer Werktitel\n\nText.\n");
    expect($c->instance()->inhaltGerendert())->toContain('<h1>')->toContain('Ganz anderer Werktitel');
});

it('die Lese-Ansicht ist der Standard, der Rohtext die Ausnahme', function () {
    // Der Editor rendert nur mit gewähltem ODER neuem Dokument — sonst steht dort der leere Schirm.
    $c = Livewire::test(WissenBrowser::class);
    expect($c->instance()->vorschau)->toBeTrue();

    // Anlegen schaltet bewusst auf Rohtext: die Vorschau eines leeren Dokuments zeigt nichts.
    $c->call('neu');
    expect($c->instance()->vorschau)->toBeFalse();
    expect($c->html())->toContain('data-wissen-inhalt');

    // In der Lese-Ansicht kein Textfeld — sonst tippt man beim Nachschlagen versehentlich hinein.
    $c->set('vorschau', true);
    expect($c->html())->not->toContain('data-wissen-inhalt');
    expect($c->html())->toContain('data-wissen-titel-lesen');
});

it('ein geöffnetes Dokument beginnt in der Lese-Ansicht, auch nach dem Schreiben', function () {
    // Über die Komponente anlegen statt per DB-Insert: das übt denselben Weg wie der Nutzer und
    // hängt nicht am Schema.
    $c = Livewire::test(WissenBrowser::class)
        ->call('neu')
        ->set('form.title', 'Maillard')
        ->set('form.content_md', "# Maillard\n\nText.")
        ->call('save');

    $id = $c->instance()->selectedId;
    expect($id)->not->toBeNull();

    // Umschalten wie beim Schreiben …
    $c->set('vorschau', false);
    // … und beim Öffnen eines Dokuments zurück in die Lese-Ansicht.
    $c->call('select', $id);
    expect($c->instance()->vorschau)->toBeTrue();
});

it('der Schirm trennt Liste, Text und Verwendung in drei Zonen', function () {
    $html = Livewire::test(WissenBrowser::class)->html();

    // Auf MARKER prüfen, nicht auf Komponenten-Namen: `<x-ui-page-sidebar>` ist im gerenderten
    // HTML wegkompiliert. `activity_knowledge` ist der Scope des rechten Panels und überlebt
    // als Alpine-Zustand.
    expect($html)->toContain('data-wissen-liste')->toContain('activity_knowledge');
});
