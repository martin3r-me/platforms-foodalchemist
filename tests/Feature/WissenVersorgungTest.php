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

it('nennt einen Key mit NUR Alt-Bindungen ehrlich UNGESTEUERT', function () {
    // ★ Umkehrung mit Spec 52 · F2. Vorher lautete das Verdikt `nur-bindung`: das Bereichs-
    // Präfix traf ALLE `recipe.*`-Prompts, zwei Dossiers hingen so an 23 Keys, und der
    // Bericht musste das benennen, damit es nicht wie „gesteuert" aussieht.
    //
    // Heute liest der Gateway keine Bindungen. Aus dem Korpus erreicht diesen Prompt also
    // NICHTS — und genau das muss der Bericht sagen. `nur-bindung` waere jetzt die
    // freundlichere, aber falsche Auskunft.
    config(['foodalchemist.prompts' => ['recipe.irgendwas' => ['tier' => 'B', 'task' => 'Tu etwas.']]]);
    ($this->mkBindung)(($this->mkDoc)('praefix-doc'), 'recipe');

    $this->artisan('foodalchemist:wissen-versorgung', ['--team' => $this->rootTeam->id])
        ->expectsOutputToContain('UNGESTEUERT')
        ->expectsOutputToContain('ALT-BINDUNGEN, die niemand mehr liest')
        ->assertExitCode(1);   // ungesteuerte Keys sind ein Befund, kein Erfolg
});

it('kennt das Verdikt `nur-bindung` nicht mehr', function () {
    expect(\Platform\FoodAlchemist\Services\Knowledge\WissensVersorgungService::VERDIKTE)
        ->toBe(['gesteuert', 'none', 'UNGESTEUERT']);
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

/**
 * Spec 52/A2 · Grundsatz E — dieselbe Antwort per MCP.
 *
 * Warum das Tool existiert, obwohl es das Kommando gibt: **auf demo gibt es keine Shell.** Ein
 * Bericht, den man nur in einer lokalen Sandbox fahren kann, misst die falsche Umgebung — genau
 * das ist beim Bau passiert (die ersten 42-von-71 kamen aus der Dev-MySQL, die dem
 * Frisch-DB-Zustand entspricht und mit demo nichts zu tun hat).
 */
it('MCP: knowledge_versorgung.GET liefert denselben Bericht wie das Kommando', function () {
    config(['foodalchemist.prompts' => [
        'test.nackt' => ['tier' => 'B', 'task' => 'Tu etwas.'],
        'test.mit_kanon' => ['tier' => 'B', 'task' => 'Tu etwas.'],
    ]]);
    ($this->mkKanon)(($this->mkDoc)('kanon-doc'), 'test.mit_kanon');

    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $res = app(\Platform\Core\Tools\ToolRegistry::class)
        ->get('foodalchemist.knowledge_versorgung.GET')
        ->execute([], new \Platform\Core\Contracts\ToolContext($user, $this->rootTeam));

    expect($res->success)->toBeTrue((string) ($res->error ?? ''))
        ->and($res->data['keys'])->toBe(2)
        ->and($res->data['gesteuert'])->toBe(1)
        ->and($res->data['ungesteuert'])->toBe(1)
        // Die Deutung muss mitkommen — ein Agent soll nicht raten, ob 1 von 2 schlimm ist.
        ->and($res->data['hinweis'])->toContain('erreicht KEIN Dossier');
});

it('MCP: eine einzelne Registry-Zeile ist abfragbar, unbekannte werden abgewiesen', function () {
    config(['foodalchemist.prompts' => ['test.eins' => ['tier' => 'B', 'task' => 'Tu etwas.']]]);
    $user = $this->makeUser($this->rootTeam);
    $this->actingAs($user);
    $kontext = new \Platform\Core\Contracts\ToolContext($user, $this->rootTeam);
    $tool = app(\Platform\Core\Tools\ToolRegistry::class)->get('foodalchemist.knowledge_versorgung.GET');

    expect($tool->execute(['prompt_key' => 'test.eins'], $kontext)->data['zeile']['prompt_key'])->toBe('test.eins')
        ->and($tool->execute(['prompt_key' => 'gibt.es.nicht'], $kontext)->errorCode)->toBe('VALIDATION_ERROR');
});

it('trennt "ohne Aufrufer gemessen" von "unklar" und "Aufrufer-Eigenname"', function () {
    // Meine erste Fassung meldete `foodbook.plan` als tot — es ist aber live, bloss kein
    // Registry-Key (IdeenService ruft contextFor damit). Und ob ein Feature WIRKLICH keinen
    // Aufrufer hat, ist statisch nicht entscheidbar, weil mehrere Stellen mit einer Variablen
    // rufen. Der Bericht darf das nicht verwischen.
    config(['foodalchemist.prompts' => ['recipe.generator' => ['tier' => 'B', 'task' => 'x']]]);
    ($this->mkRouting)('ai_plan_dishes', 'domain', 'discovery');      // gemessen ohne Aufrufer
    ($this->mkRouting)('foodbook.plan', 'domain', 'discovery');        // Aufrufer-Eigenname
    ($this->mkRouting)('voellig.unbekannt', 'domain', 'discovery');    // unklar
    ($this->mkRouting)('ai_generate_recipe', 'domain', 'discovery');   // Alias, muss NICHT auftauchen

    $rf = app(\Platform\FoodAlchemist\Services\Knowledge\WissensVersorgungService::class)
        ->routingFeaturesOhnePromptKey();

    expect($rf['ohne_aufrufer_gemessen'])->toContain('ai_plan_dishes')
        ->and($rf['aufrufer_eigenname'])->toBe(['foodbook.plan'])
        ->and($rf['ohne_prompt_key'])->toContain('voellig.unbekannt')
        // Keine Doppelnennung: was als "ohne Aufrufer" gemessen ist, steht nicht noch in "unklar".
        ->and($rf['ohne_prompt_key'])->not->toContain('ai_plan_dishes')
        // Der Alias ist bekannt und damit kein Befund.
        ->and($rf['ohne_prompt_key'])->not->toContain('ai_generate_recipe');
});

it('weist eine Bindung an einem Key MIT Kanon als stumm aus, nicht als Versorgung', function () {
    // Genau der demo-Zustand: 9 Bindungen, alle auf recipe.generator/vk.generator, alle stumm,
    // weil dort ein Kanon steht (AiGatewayService:178). Sie sehen im Browser nach Verdrahtung
    // aus und liefern nichts.
    config(['foodalchemist.prompts' => ['recipe.generator' => ['tier' => 'B', 'task' => 'x']]]);
    ($this->mkKanon)(($this->mkDoc)('kanon-regel'), 'recipe.generator');
    ($this->mkBindung)(($this->mkDoc)('alte-bindung', aktiv: true), 'recipe.generator');

    $zeile = app(\Platform\FoodAlchemist\Services\Knowledge\WissensVersorgungService::class)
        ->zeileFuer('recipe.generator', $this->rootTeam);

    expect($zeile['verdikt'])->toBe('gesteuert')
        ->and($zeile['bindungen'])->toBe(1)
        ->and($zeile['bindungen_stumm'])->toBe(1);
});
