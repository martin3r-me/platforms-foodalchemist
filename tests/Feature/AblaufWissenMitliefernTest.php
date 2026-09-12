<?php

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Gemessener Anlass 2026-09-12: Dominique liess einen MCP-Agenten ein Basisrezept bauen.
 * Der Agent rief `ablauf.GET` korrekt auf und bekam 13 verbindliche Kanon-Dossiers mit Slug,
 * Titel, Kategorie und Zeichenzahl aufgelistet — las davon ZWEI und baute den Rest „aus dem
 * Kopf". Auf demselben Vorgang zieht der Generator alle 13 automatisch in den Prompt.
 *
 * Die Liste allein bewirkt nichts: 13 Titel zu lesen kostete 13 einzelne `knowledge.GET`, und
 * nichts machte das bequem oder verbindlich. Die Lücke war REIBUNG, nicht fehlende Information
 * — und eine Fähigkeit, von der man erst im Schema erfährt, ist keine
 * (vgl. feedback_faehigkeit_ohne_kenntnis).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);
    $this->ruf = fn (array $args) => app(ToolRegistry::class)->get('foodalchemist.ablauf.GET')
        ->execute($args, new ToolContext($this->user, $this->rootTeam));

    // ⚠ Der Test legt seinen Kanon SELBST an. Vorher stand hier ein Ausweg („keine Dokumente
    // ⇒ Test bestanden") — der haette auf einer Fixture ohne Kanon nichts geprueft und waere
    // trotzdem gruen gewesen. Ein Test mit Hintertuer ist keiner.
    $text = "# Regel\n\nDies ist der Volltext, den mit_wissen=true liefern muss. "
        . str_repeat('Inhalt zum Messen der Laenge. ', 4);
    $docId = \Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_documents')->insertGetId([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => null,
        'slug' => 'regelwerk-test-volltext', 'title' => 'Regelwerk Test — Volltext',
        'category' => 'regelwerk', 'content_md' => $text, 'version' => 1,
        'content_hash' => hash('sha256', $text), 'char_count' => mb_strlen($text),
        'active' => 1, 'created_via' => 'ui', 'created_at' => now(), 'updated_at' => now(),
    ]);
    \Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_canon')->insert([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => null,
        'scope' => 'prompt_key', 'scope_key' => 'recipe.generator', 'role' => 'root', 'ord' => 10,
        'knowledge_document_id' => $docId, 'mode' => 'pflicht', 'active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('★ sagt im ERGEBNIS, dass verbindliches Wissen fehlt — nicht nur im Schema', function () {
    $res = ($this->ruf)(['vorgang' => 'basisrezept_anlegen']);

    expect($res->data['regelwerke']['dokumente'])->not->toBe([])
        ->and($res->data['hinweis'])->toContain('mit_wissen=true')
        ->and($res->data['hinweis'])->toContain('VERBINDLICHE');
});

it('★ liefert mit mit_wissen=true die Volltexte statt nur der Titel', function () {
    $ohne = ($this->ruf)(['vorgang' => 'basisrezept_anlegen'])->data['regelwerke']['dokumente'];
    expect($ohne)->not->toBe([]);
    // Ohne Schalter: kein Textfeld. Das ist der Vertrag — die Antwort bleibt schlank, solange
    // niemand danach fragt.
    expect($ohne[0])->not->toHaveKey('text');

    $mit = ($this->ruf)(['vorgang' => 'basisrezept_anlegen', 'mit_wissen' => true])
        ->data['regelwerke']['dokumente'];

    expect($mit[0])->toHaveKey('text')
        ->and($mit[0]['text'])->toBeString()
        ->and(mb_strlen($mit[0]['text']))->toBeGreaterThan(50);
});

it('der Schalter steht im Schema, damit ein Agent ihn ueberhaupt finden kann', function () {
    $schema = app(ToolRegistry::class)->get('foodalchemist.ablauf.GET')->getSchema();

    expect($schema['properties'])->toHaveKey('mit_wissen')
        ->and($schema['properties']['mit_wissen']['description'])->toContain('VOLLTEXTE');
});

/**
 * ★ Der Fix hatte selbst einen Fehler, und nur das Testen ueber den ECHTEN MCP-Weg hat ihn
 * gezeigt: alle 13 Dossiers am Stueck sind 35.075 Zeichen Text und ergaben eine
 * 50.832-Zeichen-Antwort — jenseits des Token-Limits. Der Aufruf starb, bevor der Agent ein
 * Wort davon sah. Eine Antwort, die nie ankommt, ist schlechter als eine Titelliste: sie sieht
 * nach Fortschritt aus.
 */
it('★ blaettert, statt eine Antwort zu bauen, die am Token-Limit stirbt', function () {
    // Zwei fette Dossiers, zusammen sicher ueber dem Budget von 20.000 Zeichen.
    foreach ([1, 2] as $n) {
        $text = str_repeat("Regeltext zum Fuellen des Budgets. ", 400);   // ~13.600 Z.
        $docId = \Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_documents')->insertGetId([
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => null,
            'slug' => 'regelwerk-fett-'.$n, 'title' => 'Fettes Regelwerk '.$n,
            'category' => 'regelwerk', 'content_md' => $text, 'version' => 1,
            'content_hash' => hash('sha256', 'fett'.$n), 'char_count' => mb_strlen($text),
            'active' => 1, 'created_via' => 'ui', 'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_canon')->insert([
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(), 'team_id' => null,
            'scope' => 'prompt_key', 'scope_key' => 'recipe.generator', 'role' => 'root', 'ord' => 20 + $n,
            'knowledge_document_id' => $docId, 'mode' => 'pflicht', 'active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $erste = ($this->ruf)(['vorgang' => 'basisrezept_anlegen', 'mit_wissen' => true])->data;

    expect($erste['wissen_teil']['zeichen'])->toBeLessThanOrEqual(34000)
        ->and($erste['wissen_teil']['naechstes_ab'])->not->toBeNull()
        ->and($erste['hinweis'])->toContain('Weiter mit');

    // Und der genannte Weg trägt wirklich weiter — sonst wäre der Hinweis eine Sackgasse.
    $zweite = ($this->ruf)([
        'vorgang' => 'basisrezept_anlegen', 'mit_wissen' => true,
        'ab' => $erste['wissen_teil']['naechstes_ab'],
    ])->data;

    expect($zweite['wissen_teil']['geliefert'])->toBeGreaterThan(0)
        ->and($zweite['regelwerke']['dokumente'][0]['slug'])
        ->not->toBe($erste['regelwerke']['dokumente'][0]['slug']);
});

it('meldet am Ende den vollstaendigen Kanon, nicht wieder ein „weiter"', function () {
    $res = ($this->ruf)(['vorgang' => 'basisrezept_anlegen', 'mit_wissen' => true])->data;

    expect($res['wissen_teil']['naechstes_ab'])->toBeNull()
        ->and($res['hinweis'])->toContain('vollstaendige');
});
