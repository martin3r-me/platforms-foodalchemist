<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Knowledge\KnowledgeCanonService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 52/H7 — der Defekt, den der Split erzeugt hat.
 *
 * Vorher war das Regelwerk EIN Dokument; §2 konnte inline auf §11 verweisen. Nach dem
 * Schnitt in Ein-Thema-Dossiers ist derselbe Satz ein Textstring ohne Ziel: das Modell
 * liest „siehe §11", und §11 liegt nicht im Prompt. Der Wächter meldet das, blockiert
 * aber nichts — die Auflösung (Ziel mitliefern / Verweis auflösen / entfernen) ist
 * Kuration.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->dossier = function (string $slug, string $titel, string $inhalt) {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
            'team_id' => $this->rootTeam->id, 'slug' => $slug, 'title' => $titel,
            'category' => 'regelwerk', 'content_md' => $inhalt, 'version' => 1,
            'content_hash' => hash('sha256', $inhalt), 'char_count' => mb_strlen($inhalt),
            'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(KnowledgeCanonService::class)->set($this->rootTeam, [
            'scope' => 'prompt_key', 'scope_key' => 'recipe.generator',
            'slug' => $slug, 'mode' => 'pflicht',
        ]);
    };
});

it('H7: meldet einen Verweis, dessen Ziel nicht im selben Prompt steht', function () {
    ($this->dossier)('rw-2', 'Regelwerk Basisrezepte §2 — Verarbeitungs-Reduktion',
        'Bei Derivaten gilt zusätzlich §11. Grundform siehe §2.');
    ($this->dossier)('rw-4', 'Regelwerk Basisrezepte §4 — Sub-Rezept-Hierarchie', 'Stubs nach §4.');

    $this->artisan('foodalchemist:wissen-verweise', ['--team' => $this->rootTeam->id, '--prompt-key' => ['recipe.generator'], '--json' => true])
        ->assertExitCode(1);   // hängender Verweis ⇒ Befund, nicht Erfolg
});

it('H7: ein Verweis auf ein mitgeliefertes § ist KEIN Befund', function () {
    ($this->dossier)('rw-2b', 'Regelwerk Basisrezepte §2 — Verarbeitungs-Reduktion', 'Grundform siehe §4.');
    ($this->dossier)('rw-4b', 'Regelwerk Basisrezepte §4 — Sub-Rezept-Hierarchie', 'Stubs nach §2.');

    $this->artisan('foodalchemist:wissen-verweise', ['--team' => $this->rootTeam->id, '--prompt-key' => ['recipe.generator']])
        ->expectsOutputToContain('kein hängender Verweis')
        ->assertExitCode(0);
});

it('H7: ein Verweis auf die §-FAMILIE ist gedeckt, sobald ein Unter-§ mitkommt', function () {
    // „siehe §1" meint die Familie — §1.2 im Prompt erfüllt das.
    ($this->dossier)('rw-12', 'Regelwerk Basisrezepte §1.2 — Typ-Vokabular', 'Naming nach §1.');

    $this->artisan('foodalchemist:wissen-verweise', ['--team' => $this->rootTeam->id, '--prompt-key' => ['recipe.generator']])
        ->assertExitCode(0);
});

it('H7: die Gegenrichtung ist NICHT gedeckt — §1.0 deckt keinen Verweis auf §1.10', function () {
    // Genau der Anlass-Fall: der Kanon trägt §1.0, §2 verweist auf die Anti-Patterns §1.10.
    ($this->dossier)('rw-10', 'Regelwerk Basisrezepte §1.0 — Naming-Syntax', 'Ausnahmen siehe §1.10.');

    $this->artisan('foodalchemist:wissen-verweise', ['--team' => $this->rootTeam->id, '--prompt-key' => ['recipe.generator']])
        ->expectsOutputToContain('HÄNGEND')
        ->assertExitCode(1);
});

it('H7: ohne --team bricht der Wächter ab statt gegen die falsche Partition zu prüfen', function () {
    $this->artisan('foodalchemist:wissen-verweise')->assertExitCode(2);
});
