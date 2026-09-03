<?php

use Platform\FoodAlchemist\Services\Knowledge\KnowledgeChunker;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeSectionizer;

// Reine Regel-Tests: der Sectionizer berührt keine DB, deshalb kein TestCase-Bootstrap nötig.
// Genau dafür ist er als pure Funktion gebaut — die Regeln sind ohne Migration prüfbar.

function doc(string $md, string $kategorie = 'domain', string $titel = 'Testdoc', int $id = 42): object
{
    return (object) ['id' => $id, 'slug' => 'test.doc', 'category' => $kategorie, 'title' => $titel, 'content_md' => $md, 'team_id' => 6];
}

it('zerlegt an §-Ueberschriften und baut den heading_path als Kette', function () {
    $md = <<<'MD'
## §1 Naming-Syntax

Der Rahmen.

### §1.0 Grundprinzip

Typ vor Doppelpunkt.

### §1.2 Typ-Vokabular

Kontrollierte Liste.
MD;
    $s = (new KnowledgeSectionizer())->sectionize(doc($md, 'regelwerk'));

    expect(array_column($s, 'anchor'))->toBe(['§1', '§1.0', '§1.2'])
        ->and($s[1]['heading_path'])->toBe('§1 Naming-Syntax > §1.0 Grundprinzip')
        // Die Kette ist der eigentliche Fix: sie wandert in den Vektor und macht
        // prozedurales Wissen ueber seinen ORT findbar, nicht ueber seinen Wortlaut.
        ->and($s[2]['heading_path'])->toBe('§1 Naming-Syntax > §1.2 Typ-Vokabular');
});

it('im regelwerk ist ALLES normativ ausser Changelog und Frontmatter', function () {
    // Zwei Belege aus dem Bestand, warum hier nicht nach Tabellen/Beispielen abgestuft wird:
    //  · W3-3-Messung: die »Referenz«-Tabellen der Regelwerke sind normativ (21 % des Blocks)
    //  · CLAUDE.md: »Beispiele pro Warengruppe (§19) sind verbindlich«
    // Eine Abstufung wuerde dem Critic still Pflichtwissen entziehen.
    $md = <<<'MD'
---
version: 3.3.2
---

## §19 Beispiele pro Warengruppe

| WG | Beispiel |
|---|---|
| 1 | Rind |

## Anhang A

Tabellen-Anhang.

## Changelog

- 2026-01-01 angelegt
MD;
    $s = (new KnowledgeSectionizer())->sectionize(doc($md, 'regelwerk'));
    $nach = array_combine(array_column($s, 'anchor'), array_column($s, 'kind'));

    expect($nach['meta'])->toBe('meta')
        ->and($nach['§19'])->toBe('normativ')
        ->and($nach['anhang-a'])->toBe('normativ')
        ->and($nach['changelog'])->toBe('changelog');
});

it('ausserhalb des regelwerks wird nach Anhang/Beispiel abgestuft', function () {
    $md = "## Anhang\n\nQuellen.\n\n## Beispiele\n\nSo geht es.\n\n## Technik\n\nFliesstext.";
    $s = (new KnowledgeSectionizer())->sectionize(doc($md, 'kueche'));
    $nach = array_combine(array_column($s, 'anchor'), array_column($s, 'kind'));

    expect($nach['anhang'])->toBe('referenz')
        ->and($nach['beispiele'])->toBe('beispiel')
        ->and($nach['technik'])->toBe('prosa');
});

it('Doc ohne jede Ueberschrift fällt auf Absätze — im regelwerk NORMATIV', function () {
    // Gemessen: nur 5 von 598 Docs haben keine `##`. Randfall, aber er muss im Regelwerk
    // `normativ` liefern, sonst laeuft ladeRegelwerke(kind=normativ) LEER und
    // ConformanceService merkt es nicht (isEmpty() → return '').
    $md = "Erster Absatz.\n\nZweiter Absatz.";
    $s = (new KnowledgeSectionizer())->sectionize(doc($md, 'regelwerk'));

    expect($s)->toHaveCount(1)                       // beide Absätze passen in ein Fenster
        ->and($s[0]['anchor'])->toBe('abs-1')
        ->and($s[0]['kind'])->toBe('normativ');

    $prosa = (new KnowledgeSectionizer())->sectionize(doc($md, 'domain'));
    expect($prosa[0]['kind'])->toBe('prosa');
});

it('Text VOR der ersten Ueberschrift wird eigener lead-Abschnitt', function () {
    // Sonst würde der Provenienz-/Status-Vorspann dem ersten § zugeschlagen und dessen
    // Vektor verwässern — dieselbe Klasse wie der Dossier-Vorspann in DossierText.
    $s = (new KnowledgeSectionizer())->sectionize(doc("Verbindlich ab heute.\n\n## §2 Regel\n\nInhalt.", 'regelwerk'));

    expect($s[0]['anchor'])->toBe('lead')
        ->and($s[1]['anchor'])->toBe('§2');
});

it('reine Gliederungs-Ueberschrift ohne Text erzeugt keinen leeren Vektor', function () {
    $s = (new KnowledgeSectionizer())->sectionize(doc("## §1 Rahmen\n\n### §1.1 Detail\n\nHier steht was.", 'regelwerk'));

    expect(array_column($s, 'anchor'))->toBe(['§1.1']);
});

it('kappt anchor und heading_path defensiv — MySQL erzwingt die Laenge, SQLite nicht', function () {
    // Dieselbe Engine-Luecke, die den --apply des Steuerdaten-Kommandos live mit
    // SQLSTATE[22001] zerlegt hat: die gruene SQLite-Suite fängt es NICHT.
    $lang = str_repeat('Sehr lange Ueberschrift ', 30);
    $s = (new KnowledgeSectionizer())->sectionize(doc("## {$lang}\n\nInhalt.", 'domain'));

    expect(mb_strlen($s[0]['anchor']))->toBeLessThanOrEqual(KnowledgeSectionizer::ANCHOR_MAX)
        ->and(mb_strlen($s[0]['heading_path']))->toBeLessThanOrEqual(KnowledgeSectionizer::HEADING_PATH_MAX);
});

it('Chunker: ein kurzer Abschnitt bleibt GENAU ein Chunk', function () {
    $s = [['id' => 1, 'kind' => 'normativ', 'heading_path' => '§2 Regel', 'title' => '§2 Regel', 'body_md' => 'Kurz und scharf.']];
    $c = (new KnowledgeChunker())->chunk(doc('', 'regelwerk', 'Regelwerk Basisrezepte'), $s);

    expect($c)->toHaveCount(1)
        // Der Kopf ist der Wert: Kategorie · Doc-Titel · Pfad wandern MIT in den Vektor.
        ->and($c[0]['embed_text'])->toStartWith('regelwerk · Regelwerk Basisrezepte · §2 Regel')
        ->and($c[0]['entity_key'])->toBe('42#000')
        ->and($c[0]['regal'])->toBe('regelwerk');
});

it('Chunker: langer Abschnitt wird geschnitten, mit Ueberlappung und ohne Textverlust', function () {
    $body = implode("\n\n", array_map(fn ($i) => "Absatz {$i}: " . str_repeat('Inhalt ', 40), range(1, 12)));
    $s = [['id' => 1, 'kind' => 'normativ', 'heading_path' => '§6 Mengen', 'title' => null, 'body_md' => $body]];
    $c = (new KnowledgeChunker())->chunk(doc('', 'regelwerk', 'RW'), $s);

    expect(count($c))->toBeGreaterThan(1);

    // Invariante statt Beispiel: JEDES Fenster liegt unter MAX. Gemessen wird das FENSTER,
    // nicht char_count — letzteres enthält den Kopf und darf grösser sein.
    foreach ($c as $stueck) {
        $fenster = mb_substr($stueck['embed_text'], mb_strpos($stueck['embed_text'], "\n\n") + 2);
        expect(mb_strlen($fenster))->toBeLessThanOrEqual(KnowledgeChunker::MAX);
    }

    // Fortlaufende, eindeutige Schluessel — `entity_key` ist UNIQUE in der Tabelle.
    expect(array_unique(array_column($c, 'entity_key')))->toHaveCount(count($c));

    // Kein Textverlust: der Anfang des ersten und das Ende des letzten Fensters müssen da sein.
    expect($c[0]['embed_text'])->toContain('Absatz 1:')
        ->and(end($c)['embed_text'])->toContain('Absatz 12:');
});

it('Chunker laesst pairing, changelog und meta bewusst aus', function () {
    $s = [
        ['id' => 1, 'kind' => 'changelog', 'heading_path' => 'Changelog', 'title' => 'Changelog', 'body_md' => '- alt'],
        ['id' => 2, 'kind' => 'meta', 'heading_path' => '', 'title' => null, 'body_md' => 'version: 1'],
        ['id' => 3, 'kind' => 'prosa', 'heading_path' => 'Technik', 'title' => 'Technik', 'body_md' => 'Echter Inhalt.'],
    ];
    // Changelogs sind gemessen 33.015 Zeichen = 20 % des importierten Korpus — Vektoren
    // dafuer sind reine Kosten.
    expect((new KnowledgeChunker())->chunk(doc('', 'domain'), $s))->toHaveCount(1);

    // pairing hat einen eigenen, kuratierten Embed-Text (Zutat + Partner-Namen), den
    // Zerschneiden zerstoeren wuerde.
    expect((new KnowledgeChunker())->chunk(doc('', 'pairing'), $s))->toBeEmpty();
});
