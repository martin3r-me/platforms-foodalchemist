<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

// SeedsTeamHierarchy zieht die FA-Migrationen mit — ohne die Trait fehlen die
// foodalchemist_knowledge_*-Tabellen (Harness-Eigenheit, vgl. WissenDeckungTest).
uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 52 · A2 — der Versorgungs-Bericht.
 *
 * Gemessener Anlass: auf der Dev-MySQL (die dem Frisch-DB-Zustand entspricht) bekommen
 * **42 von 71** Prompt-Keys weder Kanon noch Routing noch Bindung, und `geschmacksbalance`
 * hängt über das Bereichs-Präfix `recipe` an **23** Keys, unabhängig von jeder Relevanz.
 * Beides war vorher unsichtbar, weil drei Steuertabellen unabhängig voneinander antworten.
 *
 * Zwei Befunde werden hier als VERTRAG festgeschrieben, weil sie sonst wieder verloren gehen:
 *   · der Alt-Schlüssel (`ai_generate_recipe` versorgt `recipe.generator`) — ein Aufruf trägt
 *     zwei Identitäten, und wer das nicht weiss, hält die Generatoren für ungesteuert;
 *   · eine Bindung auf ein INAKTIVES Dossier ist keine Versorgung — genau so ist beim
 *     155-Originale-Cutover still Wissen verschwunden.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->mkDoc = function (string $slug, bool $aktiv = true, string $kategorie = 'cross_cutting'): int {
        $inhalt = "## Inhalt von {$slug}\nText.";

        return (int) DB::table('foodalchemist_knowledge_documents')->insertGetId([
            'uuid' => (string) UuidV7::generate(), 'team_id' => null, 'slug' => $slug,
            'title' => 'Titel '.$slug, 'category' => $kategorie, 'content_md' => $inhalt,
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

    $this->mkBindung = function (int $docId, string $targetKey): void {
        DB::table('foodalchemist_knowledge_bindings')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => null,
            'knowledge_document_id' => $docId, 'binding_type' => 'layer', 'target_key' => $targetKey,
            'mode' => 'always', 'weight' => 0, 'active' => 1, 'source' => 'ui',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    // updateOrInsert, nicht insert: die Migrationen seeden Routing-Zeilen mit, und
    // (feature, category) ist unique — ein blindes insert stirbt am Bestand.
    $this->mkRouting = function (string $feature, string $kategorie, string $mode): void {
        DB::table('foodalchemist_knowledge_routings')->updateOrInsert(
            ['feature' => $feature, 'category' => $kategorie],
            ['mode' => $mode, 'updated_at' => now(), 'created_at' => now()],
        );
    };
});

it('meldet einen Prompt-Key ohne Kanon, Routing und Bindung als Befund', function () {
    config(['foodalchemist.prompts' => ['test.nackt' => ['tier' => 'B', 'task' => 'Tu etwas.']]]);

    $this->artisan('foodalchemist:wissen-versorgung', ['--team' => $this->rootTeam->id])
        ->expectsOutputToContain('UNGESTEUERT')
        ->expectsOutputToContain('test.nackt')
        ->assertExitCode(1);
});

it('zaehlt eine Kanon-Zeile als Versorgung', function () {
    config(['foodalchemist.prompts' => ['test.mit_kanon' => ['tier' => 'B', 'task' => 'Tu etwas.']]]);
    ($this->mkKanon)(($this->mkDoc)('kanon-doc'), 'test.mit_kanon');

    $this->artisan('foodalchemist:wissen-versorgung', ['--team' => $this->rootTeam->id])
        ->expectsOutputToContain('UNGESTEUERT 0')
        ->assertExitCode(0);
});

it('behandelt mode=none als bewusste Entscheidung, nicht als Luecke', function () {
    // `ai_extract_recipe|cross_cutting|none` ist im Bestand ausdrücklich gewollt (Golden-Test
    // „Inv. 7"). Wer das als Befund meldet, erzeugt Rauschen, das niemand mehr liest.
    config(['foodalchemist.prompts' => ['test.leer' => ['tier' => 'B', 'task' => 'Tu etwas.']]]);
    ($this->mkRouting)('test.leer', 'cross_cutting', 'none');

    $this->artisan('foodalchemist:wissen-versorgung', ['--team' => $this->rootTeam->id])
        ->expectsOutputToContain('bewusst none 1')
        ->assertExitCode(0);
});

it('macht sichtbar, wenn ein Key NUR ueber die Alt-Struktur versorgt wird', function () {
    // Das Bereichs-Präfix trifft ALLE `recipe.*`-Prompts (AiGatewayService:179). Gemessen
    // hängen so zwei Dossiers an 23 Keys — der Bericht muss das benennen, sonst sieht es
    // wie „gesteuert" aus, obwohl kein Mensch diese Zuordnung je entschieden hat.
    config(['foodalchemist.prompts' => ['recipe.irgendwas' => ['tier' => 'B', 'task' => 'Tu etwas.']]]);
    ($this->mkBindung)(($this->mkDoc)('praefix-doc'), 'recipe');

    $this->artisan('foodalchemist:wissen-versorgung', ['--team' => $this->rootTeam->id])
        ->expectsOutputToContain('nur-bindung')
        ->expectsOutputToContain('Nur über die ALT-Struktur versorgt')
        ->assertExitCode(0);
});

it('loest den Alt-Schluessel auf: ein Routing auf ai_generate_recipe versorgt recipe.generator', function () {
    // Befund B5 als Vertrag: `RecipeGenerationContextService:88` ruft contextFor() mit dem
    // hartkodierten Alt-Feature, während der Kanon über den Prompt-Key aufgelöst wird. Ein
    // Bericht, der nur `feature = prompt_key` prüft, hielte beide Generatoren für ungesteuert.
    config(['foodalchemist.prompts' => ['recipe.generator' => ['tier' => 'B', 'task' => 'Tu etwas.']]]);
    ($this->mkRouting)('ai_generate_recipe', 'domain', 'discovery');

    $this->artisan('foodalchemist:wissen-versorgung', ['--team' => $this->rootTeam->id])
        ->expectsOutputToContain('ai_generate_recipe (alt)')
        ->expectsOutputToContain('UNGESTEUERT 0')
        ->assertExitCode(0);
});

it('zaehlt eine Bindung auf ein INAKTIVES Dossier nicht als Versorgung', function () {
    // Genau so ist beim Cutover der 155 Originale still Wissen verschwunden: die Bindung
    // blieb stehen, das Ziel wurde deaktiviert, und `crossCuttingDocs()` übersprang es
    // lautlos. Eine tote Bindung darf nie wie Versorgung aussehen.
    config(['foodalchemist.prompts' => ['recipe.totgebunden' => ['tier' => 'B', 'task' => 'Tu etwas.']]]);
    ($this->mkBindung)(($this->mkDoc)('totes-doc', aktiv: false), 'recipe.totgebunden');

    $this->artisan('foodalchemist:wissen-versorgung', ['--team' => $this->rootTeam->id])
        ->expectsOutputToContain('UNGESTEUERT')
        ->expectsOutputToContain('1 tot')
        ->assertExitCode(1);
});

it('bricht ohne gueltigen Team-Kontext ab statt eine falsche Deckungsluecke zu behaupten', function () {
    // Ohne Nutzer greift nur die globale Partition — der Kanon sähe fast leer aus. Genau die
    // Beinahe-Fehldiagnose aus der Semantik-Messung („Suche ist kaputt", war der fehlende
    // --team-Schalter).
    config(['foodalchemist.prompts' => ['test.x' => ['tier' => 'B', 'task' => 'Tu etwas.']]]);

    $this->artisan('foodalchemist:wissen-versorgung', ['--team' => 999999])
        ->expectsOutputToContain('nicht aussagekräftig')
        ->assertExitCode(1);
});
