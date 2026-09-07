<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\VorgangsRegisterService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 50 · Etappe 8b — der Wächter über zwei stille Invarianten.
 *
 * Beide Fehler dieser Klasse sind am 2026-09-06/07 real passiert:
 * ein 10.151-Zeichen-Dossier trotz Server-Warnung, und ein angelegtes, aktives Dossier, das
 * im Register fehlte und darum von `ablauf.GET` **nie** ausgeliefert wurde.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->user = $this->makeUser($this->rootTeam);
    $this->actingAs($this->user);

    $this->mkDoc = function (string $slug, string $inhalt, string $kategorie = 'workflow'): void {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => (int) $this->rootTeam->id, 'slug' => $slug,
            'title' => 'Titel ' . $slug, 'category' => $kategorie, 'content_md' => $inhalt, 'version' => 1,
            'content_hash' => hash('sha256', $slug), 'char_count' => mb_strlen($inhalt),
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('meldet ein Dossier über dem Deckel und nennt, ob es global ist', function () {
    // Global heisst: über MCP nicht editierbar — der Weg führt nur über eine Migration.
    // Diese Unterscheidung entscheidet, wer den Befund überhaupt beheben kann.
    ($this->mkDoc)('zu-gross', str_repeat('x', 4200));

    $this->artisan('foodalchemist:wissen-deckel-check')
        ->expectsOutputToContain('über dem Deckel')
        ->assertExitCode(1);
});

it('meldet ein Dossier im Warnband, ohne es als Fehler zu werten', function () {
    // 3.950 Zeichen: unter dem Deckel, aber die nächste Ergänzung kippt es.
    ($this->mkDoc)('knapp-drunter', str_repeat('x', 3950));

    $this->artisan('foodalchemist:wissen-deckel-check')
        ->expectsOutputToContain('Warnband')
        ->assertExitCode(0);
});

it('findet das Dossier, das einen Vorgang nennt, aber im Register fehlt', function () {
    // GENAU der Fehler vom 2026-09-07: `workflow.gericht_abschluss` war angelegt und aktiv,
    // stand aber nicht in `doc_slugs` — `ablauf.GET` lieferte es nie, und weil das Register
    // es nicht kannte, konnte es auch nicht als fehlend gemeldet werden. Kein Test konnte
    // das finden; nur ein Blick von der Wissensbasis auf das Register findet es.
    ($this->mkDoc)('workflow.verwaistes_teil', "---\ngilt_fuer_vorgang: gericht_anlegen\n---\n\n# Teil\n\nText.");

    $this->artisan('foodalchemist:wissen-deckel-check')
        ->expectsOutputToContain('ablauf.GET liefert es NIE')
        ->assertExitCode(1);
});

it('meldet ein Dossier, das unter dem falschen Vorgang verdrahtet ist', function () {
    // Das Dossier gehört laut eigenem Kopf zu `konzept_anlegen`, hängt im Register aber am
    // Gericht — dann bekommt der falsche Vorgang fremde Prosa.
    $slug = VorgangsRegisterService::VORGAENGE['gericht_anlegen']['doc_slugs'][0];
    ($this->mkDoc)($slug, "---\ngilt_fuer_vorgang: konzept_anlegen\n---\n\n# Falsch verdrahtet\n\nText.");

    $this->artisan('foodalchemist:wissen-deckel-check')
        ->expectsOutputToContain('Register führt es unter')
        ->assertExitCode(1);
});

it('ist still, wenn alles sauber ist', function () {
    ($this->mkDoc)('harmlos', "# Klein\n\nText.", 'cross_cutting');

    $this->artisan('foodalchemist:wissen-deckel-check')
        ->expectsOutputToContain('Alles sauber')
        ->assertExitCode(0);
});

it('--nur-deckel überspringt die Verdrahtungs-Prüfung', function () {
    ($this->mkDoc)('workflow.verwaistes_teil', "---\ngilt_fuer_vorgang: gericht_anlegen\n---\n\n# Teil\n\nText.");

    // Ohne Flag wäre das ein Befund; mit Flag interessiert nur der Zeichen-Deckel.
    $this->artisan('foodalchemist:wissen-deckel-check', ['--nur-deckel' => true])
        ->assertExitCode(0);
});
