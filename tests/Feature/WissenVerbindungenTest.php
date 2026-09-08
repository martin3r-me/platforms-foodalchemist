<?php

use Illuminate\Support\Facades\DB;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Tools\ToolRegistry;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeLinkService;
use Platform\FoodAlchemist\Services\Knowledge\WissensProfilService;
use Platform\FoodAlchemist\Services\Knowledge\Wissensverbindung;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 52 · H6 — Verbindungen zwischen Dossiers, allen voran `ersetzt`.
 *
 * Der Anlass ist der Korpus-Umbau: aus einem alten Dossier werden zwei, aus dreien eins. Diese
 * Information lässt sich hinterher **nicht rekonstruieren** — anders als eine vergessene
 * Kategorie, die man nachträgt, indem man das Dossier liest.
 *
 * Und sie ist nicht dekorativ: zeigt eine Kanon-Zeile auf ein abgelöstes Dossier, nennt der
 * Integritäts-Bericht den Nachfolger. Aus „etwas ist kaputt" wird „häng die Zeile dorthin um".
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->mkDoc = function (string $slug, bool $aktiv = true, ?int $teamId = null): int {
        $inhalt = "## {$slug}\nInhalt.";

        return (int) DB::table('foodalchemist_knowledge_documents')->insertGetId([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId, 'slug' => $slug,
            'title' => 'Titel '.$slug, 'category' => 'regelwerk', 'content_md' => $inhalt,
            'version' => 1, 'content_hash' => hash('sha256', $slug), 'char_count' => mb_strlen($inhalt),
            'active' => $aktiv ? 1 : 0, 'created_via' => 'ui', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $this->mkKanon = function (int $docId, string $scopeKey): void {
        DB::table('foodalchemist_knowledge_canon')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => null,
            'scope' => 'prompt_key', 'scope_key' => $scopeKey, 'role' => 'root', 'ord' => 10,
            'knowledge_document_id' => $docId, 'mode' => 'pflicht', 'active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('nennt den Nachfolger, wenn eine Kanon-Zeile auf ein abgeloestes Dossier zeigt', function () {
    // DAS ist der Tagesnutzen von H6. Ohne die Kante meldet der Bericht nur „deaktiviert" und
    // der Kurator muss selbst suchen, was an die Stelle getreten ist.
    config(['foodalchemist.prompts' => ['test.key' => ['tier' => 'B', 'task' => 'x']]]);
    $alt = ($this->mkDoc)('regel-alt', aktiv: false);
    ($this->mkDoc)('regel-neu');
    ($this->mkKanon)($alt, 'test.key');
    app(KnowledgeLinkService::class)->set($this->rootTeam, 'regel-neu', 'regel-alt', Wissensverbindung::ERSETZT);

    $p = app(WissensProfilService::class)->profil('test.key', $this->rootTeam);
    $befund = collect($p['befunde'])->firstWhere('code', 'dossier_inaktiv');

    expect($befund['nachfolger'])->toBe(['regel-neu'])
        ->and($befund['text'])->toContain('Kanon-Zeile dorthin umhängen');
});

it('haelt drei alte Dossiers zusammen, die zu einem neuen wurden', function () {
    // Der reale Schnitt-Fall: aus drei mach eins. Jede der drei Kanten zeigt auf denselben
    // Nachfolger, und jedes alte Dossier findet ihn.
    ['a' => $a, 'b' => $b, 'c' => $c] = [
        'a' => ($this->mkDoc)('alt-a', aktiv: false),
        'b' => ($this->mkDoc)('alt-b', aktiv: false),
        'c' => ($this->mkDoc)('alt-c', aktiv: false),
    ];
    ($this->mkDoc)('neu-gesamt');
    $dienst = app(KnowledgeLinkService::class);
    foreach (['alt-a', 'alt-b', 'alt-c'] as $alt) {
        $dienst->set($this->rootTeam, 'neu-gesamt', $alt, Wissensverbindung::ERSETZT);
    }

    // Nur die eigenen Slugs pruefen: die Fixture bringt die Migrationen mit, und die haben
    // eigene abgeloeste Dossiers (siehe der Nachtrag-Test unten). Ein `toBe([])` waere ein
    // Test gegen den Migrationsstand, nicht gegen den Mechanismus.
    $eigene = array_values(array_intersect(
        $dienst->abgeloestOhneNachfolger($this->rootTeam),
        ['alt-a', 'alt-b', 'alt-c'],
    ));

    expect($dienst->nachfolgerVon($this->rootTeam, 'alt-b'))->toBe(['neu-gesamt'])
        ->and($eigene)->toBe([]);
});

it('listet abgeloeste Dossiers ohne benannten Nachfolger', function () {
    ($this->mkDoc)('verwaist', aktiv: false);
    ($this->mkDoc)('sauber-abgeloest', aktiv: false);
    ($this->mkDoc)('nachfolger');
    app(KnowledgeLinkService::class)->set($this->rootTeam, 'nachfolger', 'sauber-abgeloest', Wissensverbindung::ERSETZT);

    $liste = app(KnowledgeLinkService::class)->abgeloestOhneNachfolger($this->rootTeam);

    expect($liste)->toContain('verwaist')
        ->and($liste)->not->toContain('sauber-abgeloest');
});

it('haelt den Split nach, der vor dieser Tabelle passiert ist', function () {
    // Die Migration traegt die Nachfolge des 2026-09-07-Splits nach, solange die Zuordnung
    // noch bekannt ist — sie stand vorher nur im Docblock jener Migration. Ohne den Nachtrag
    // melden die zwei Monolithen auf Dauer „abgeloest ohne Nachfolger".
    //
    // `workflow.gericht_anlegen_mcp` bleibt hier absichtlich unbelegt: seine vier Nachfolger
    // sind TEAM-eigene Dossiers, die keine Migration anlegt. Auf demo existieren sie, in der
    // Fixture nicht — der Nachtrag ueberspringt fehlende Slugs still, statt zu scheitern.
    $dienst = app(KnowledgeLinkService::class);

    expect($dienst->nachfolgerVon($this->rootTeam, 'workflow.rezept_anlegen_mcp'))
        ->toContain('workflow.basisrezept_regeln')
        ->toContain('workflow.basisrezept_abschluss')
        ->and($dienst->abgeloestOhneNachfolger($this->rootTeam))
        ->not->toContain('workflow.rezept_anlegen_mcp');
});

it('weist eine Nachfolge-Schleife ab', function () {
    // Ohne diesen Riegel liefe die Nachfolger-Empfehlung im Kreis.
    ($this->mkDoc)('eins');
    ($this->mkDoc)('zwei');
    ($this->mkDoc)('drei');
    $dienst = app(KnowledgeLinkService::class);
    $dienst->set($this->rootTeam, 'eins', 'zwei', Wissensverbindung::ERSETZT);
    $dienst->set($this->rootTeam, 'zwei', 'drei', Wissensverbindung::ERSETZT);

    expect(fn () => $dienst->set($this->rootTeam, 'drei', 'eins', Wissensverbindung::ERSETZT))
        ->toThrow(RuntimeException::class, 'Nachfolge-Schleife');
});

it('erlaubt Gegenseitigkeit bei siehe_auch, aber nie Selbstbezug', function () {
    // `siehe_auch` in beide Richtungen ist normal — nur `ersetzt` braucht den Kreis-Riegel.
    ($this->mkDoc)('links');
    ($this->mkDoc)('rechts');
    $dienst = app(KnowledgeLinkService::class);
    $dienst->set($this->rootTeam, 'links', 'rechts', Wissensverbindung::SIEHE_AUCH);
    $dienst->set($this->rootTeam, 'rechts', 'links', Wissensverbindung::SIEHE_AUCH);

    expect($dienst->fuerDossier($this->rootTeam, 'links')['raus'])->toHaveCount(1)
        ->and($dienst->fuerDossier($this->rootTeam, 'links')['rein'])->toHaveCount(1)
        ->and(fn () => $dienst->set($this->rootTeam, 'links', 'links', Wissensverbindung::SIEHE_AUCH))
        ->toThrow(RuntimeException::class, 'mit sich selbst');
});

it('laesst auf fremdes Wissen VERWEISEN, aber nicht daran schreiben', function () {
    // Die Kante gehoert dem Ausgangs-Dossier. Ein Team muss seine eigenen Dossiers auf den
    // geerbten Master-Katalog beziehen duerfen — sonst waere `verfeinert` fuer Kundenteams tot.
    // Umgekehrt darf niemand am globalen Katalog Kanten aufhaengen.
    ($this->mkDoc)('global-regel', teamId: null);
    ($this->mkDoc)('eigenes', teamId: $this->childA->id);
    $dienst = app(KnowledgeLinkService::class);

    $dienst->set($this->childA, 'eigenes', 'global-regel', Wissensverbindung::VERFEINERT);
    expect($dienst->fuerDossier($this->childA, 'eigenes')['raus'][0]['slug'])->toBe('global-regel');

    expect(fn () => $dienst->set($this->childA, 'global-regel', 'eigenes', Wissensverbindung::SIEHE_AUCH))
        ->toThrow(RuntimeException::class, 'Master-/Seed-Wissen');
});

it('MCP: setzt, liest und loest eine Verbindung', function () {
    ($this->mkDoc)('quelle');
    ($this->mkDoc)('ziel');
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $kontext = new ToolContext($user, $this->rootTeam);
    $tool = app(ToolRegistry::class)->get('foodalchemist.knowledge_links.SET');

    $gesetzt = $tool->execute(['action' => 'set', 'von' => 'quelle', 'nach' => 'ziel', 'art' => 'ersetzt'], $kontext);
    $gelesen = $tool->execute(['action' => 'get', 'slug' => 'ziel'], $kontext);
    $geloest = $tool->execute(['action' => 'delete', 'von' => 'quelle', 'nach' => 'ziel', 'art' => 'ersetzt'], $kontext);

    expect($gesetzt->success)->toBeTrue((string) ($gesetzt->error ?? ''))
        ->and($gesetzt->data['hinweis'])->toContain('Nachfolger')
        ->and($gelesen->data['nachfolger'])->toBe(['quelle'])
        ->and($geloest->data['geloest'])->toBeTrue()
        ->and($tool->execute(['action' => 'get', 'slug' => 'ziel'], $kontext)->data['nachfolger'])->toBe([]);
});

it('MCP: weist eine erfundene Art und fehlende Argumente ab', function () {
    ($this->mkDoc)('a');
    ($this->mkDoc)('b');
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $kontext = new ToolContext($user, $this->rootTeam);
    $tool = app(ToolRegistry::class)->get('foodalchemist.knowledge_links.SET');

    expect($tool->execute(['action' => 'set', 'von' => 'a', 'nach' => 'b', 'art' => 'quatsch'], $kontext)->errorCode)
        ->toBe('VALIDATION_ERROR')
        ->and($tool->execute(['action' => 'set', 'von' => 'a'], $kontext)->errorCode)->toBe('VALIDATION_ERROR')
        ->and($tool->execute(['action' => 'quatsch'], $kontext)->errorCode)->toBe('VALIDATION_ERROR');
});
