<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

// SeedsTeamHierarchy zieht die FA-Migrationen mit — ohne die Trait fehlen die
// foodalchemist_knowledge_*-Tabellen (Harness-Eigenheit, vgl. WissenExportTest).
uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * W2-4: der Riegel gegen einen echten, teuer gefundenen Fehler. Der Generator-Task kündigte
 * „§12 Zutaten-/Komponenten-Reihenfolge" an — und im Modul existierte KEIN Dossier, das §12
 * enthielt: der §-Dossier-Split (2026-08-27) hatte ihn verloren. Das Modell wurde auf eine
 * Regel verwiesen, die es nie zu lesen bekam, und nichts schlug an, weil Prompt-Texte und
 * Korpus-Inhalte nirgends gegeneinander gehalten wurden.
 */
function deckDoc(string $slug, string $inhalt): void
{
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => null, 'slug' => $slug,
        'title' => $slug, 'category' => 'regelwerk', 'content_md' => $inhalt,
        'version' => 1, 'content_hash' => hash('sha256', $slug . $inhalt),
        'char_count' => mb_strlen($inhalt), 'active' => 1, 'source_path' => null,
        'created_via' => 'ui', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

beforeEach(function () {
    $this->seedTeamHierarchy();
});

it('meldet den §12-Fall: ein Prompt verspricht einen §, den kein Dossier hat', function () {
    // Korpus kennt §2 und §5, NICHT §12 — genau die Lage vom 2026-08-27.
    deckDoc('regelwerk-basisrezepte-2-verarbeitung', "## §2 Verarbeitungs-Reduktion\nText.");
    deckDoc('regelwerk-basisrezepte-5-default-gps', "## §5 Default-GPs\nText.");

    config(['foodalchemist.prompts' => [
        'test.generator' => ['tier' => 'B', 'task' => 'Beachte §2, §5 und §12 (Komponenten-Reihenfolge).'],
    ]]);

    $this->artisan('foodalchemist:wissen-deckung')
        ->expectsOutputToContain('LÜCKE')
        ->expectsOutputToContain('§12')
        ->assertSuccessful();
});

it('mit --fail-on-gap wird die Lücke zum Fehler-Exit (für Runbooks/CI)', function () {
    deckDoc('regelwerk-x-1', "## §1 Naming\nText.");
    config(['foodalchemist.prompts' => ['test.k' => ['tier' => 'B', 'task' => 'Beachte §1 und §99.']]]);

    $this->artisan('foodalchemist:wissen-deckung', ['--fail-on-gap' => true])->assertFailed();
});

it('keine Lücke: alle genannten § kommen vor — auch über den Slug erkannt', function () {
    // Die gesplitteten Dossiers tragen die Nummer im SLUG, nicht als «## §n» im Körper.
    deckDoc('regelwerk-basisrezepte-6-mengen-einheiten-yield', "Mengen, Einheiten, Yield.\nKein Paragraphenzeichen im Text.");
    deckDoc('regelwerk-gp-9-zustand', "## §9 Zustand\nText.");

    config(['foodalchemist.prompts' => ['test.k' => ['tier' => 'B', 'task' => 'Beachte §6 und §9.']]]);

    $this->artisan('foodalchemist:wissen-deckung')
        ->expectsOutputToContain('Jeder genannte § kommt im aktiven Regelwerk-Korpus vor')
        ->assertSuccessful();
});

it('inaktive Dossiers decken nicht — Quarantäne ist keine Deckung', function () {
    deckDoc('regelwerk-aktiv-1', "## §1 Naming\nText.");
    DB::table('foodalchemist_knowledge_documents')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => null, 'slug' => 'regelwerk-inaktiv-7',
        'title' => 'regelwerk-inaktiv-7', 'category' => 'regelwerk', 'content_md' => '## §7 Allergene',
        'version' => 1, 'content_hash' => hash('sha256', 'inaktiv'), 'char_count' => 16,
        'active' => 0, 'source_path' => null, 'created_via' => 'ui',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    config(['foodalchemist.prompts' => ['test.k' => ['tier' => 'B', 'task' => 'Beachte §1 und §7.']]]);

    $this->artisan('foodalchemist:wissen-deckung')
        ->expectsOutputToContain('§7')
        ->expectsOutputToContain('LÜCKE')
        ->assertSuccessful();
});

it('ohne §-Nennung im Prompt gibt es nichts zu prüfen', function () {
    deckDoc('regelwerk-x-1', '## §1 Naming');
    config(['foodalchemist.prompts' => ['test.k' => ['tier' => 'B', 'task' => 'Schreibe einen Text.']]]);

    $this->artisan('foodalchemist:wissen-deckung')
        ->expectsOutputToContain('Kein Prompt nennt einen §')
        ->assertSuccessful();
});
