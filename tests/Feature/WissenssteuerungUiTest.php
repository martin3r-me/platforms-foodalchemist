<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Wissenssteuerung;
use Platform\FoodAlchemist\Tests\Support\SeedsKanon;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class, SeedsKanon::class);

/**
 * Spec 52 · Grundsatz E — die Wissens-Steuerung hat eine Oberfläche.
 *
 * Die Asymmetrie, die das ausgelöst hat: die ALTE Bindungs-Ebene hat seit #469 eine
 * Kurations-UI, der Kanon (die gewinnende Ebene) keine, die Routings gar nichts. Wer im UI
 * kuratierte, pflegte den Fallback.
 *
 * ⚠ `Livewire::test` sieht das Layout NICHT — ein grüner Test hier schliesst einen 500er im
 * Browser nicht aus (vgl. `feedback_fa_test_harness_layout_blind`). Deshalb zusätzlich die
 * Dev-MySQL-Browserprobe vor dem Deploy.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->mkRouting = fn (string $feature, string $kategorie, string $mode = 'discovery') => DB::table('foodalchemist_knowledge_routings')->updateOrInsert(
        ['feature' => $feature, 'category' => $kategorie],
        ['mode' => $mode, 'created_at' => now(), 'updated_at' => now()],
    );

    $this->mkDoc = function (string $slug, bool $aktiv = true, string $inhalt = '# Regel'): void {
        DB::table('foodalchemist_knowledge_documents')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => null, 'slug' => $slug,
            'title' => 'Titel '.$slug, 'category' => 'regelwerk', 'content_md' => $inhalt,
            'version' => 1, 'content_hash' => hash('sha256', $slug), 'char_count' => mb_strlen($inhalt),
            'active' => $aktiv ? 1 : 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };
});

it('zeigt Zustand und Befunde je Prompt-Key', function () {
    config(['foodalchemist.prompts' => [
        'test.gesteuert' => ['tier' => 'B', 'task' => 'x'],
        'test.nackt' => ['tier' => 'B', 'task' => 'x'],
    ]]);
    ($this->mkRouting)('test.gesteuert', 'cross_cutting');

    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(Wissenssteuerung::class)
        ->assertSee('test.gesteuert')
        ->assertSee('test.nackt')
        ->assertSee('ungesteuert');
});

it('setzt ein Routing und meldet, dass es ab dem naechsten Aufruf wirkt', function () {
    config(['foodalchemist.prompts' => ['recipe.geschmack' => ['tier' => 'B', 'task' => 'x']]]);
    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(Wissenssteuerung::class)
        ->set('form.feature', 'recipe.geschmack')
        ->set('form.category', 'cross_cutting')
        ->set('form.mode', 'discovery')
        ->set('form.max_docs', '2')
        ->set('form.max_chars_per_doc', '3000')
        ->call('save')
        ->assertHasNoErrors();

    $z = DB::table('foodalchemist_knowledge_routings')
        ->where('feature', 'recipe.geschmack')->where('category', 'cross_cutting')->first();

    expect($z)->not->toBeNull()
        ->and($z->mode)->toBe('discovery')
        ->and((int) $z->max_docs)->toBe(2)
        ->and((int) $z->max_chars_per_doc)->toBe(3000);
});

it('weist einen unbekannten Modus ab, statt ihn zu speichern', function () {
    // `mode` landet im Prompt-Bau als Weiche (always → regelwerkBlock mit ->first(),
    // discovery → Jaccard). Ein Tippfehler waere eine stille Verhaltensaenderung.
    config(['foodalchemist.prompts' => ['test.x' => ['tier' => 'B', 'task' => 'x']]]);
    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(Wissenssteuerung::class)
        ->set('form.feature', 'test.x')->set('form.category', 'cross_cutting')
        ->set('form.mode', 'quatsch')
        ->call('save')
        ->assertSet('fehler', 'Modus muss always, discovery, grounding oder none sein.');

    expect(DB::table('foodalchemist_knowledge_routings')->where('feature', 'test.x')->exists())->toBeFalse();
});

it('laesst ein Kind-Team die globalen Routings NICHT aendern', function () {
    // `foodalchemist_knowledge_routings` hat keine team_id — die Zeilen gelten fuer JEDES Team.
    // Ein Kind-Team koennte damit die KI-Versorgung aller Mandanten stilllegen. Dieselbe
    // Master-Regel wie bei globalem Wissen; in `Einsatzorte` fehlte sie historisch ganz.
    config(['foodalchemist.prompts' => ['test.x' => ['tier' => 'B', 'task' => 'x']]]);
    ($this->mkRouting)('test.x', 'cross_cutting');
    $zeile = DB::table('foodalchemist_knowledge_routings')->where('feature', 'test.x')->first();

    $this->actingAs($this->makeUser($this->childA));

    Livewire::test(Wissenssteuerung::class)
        ->call('delete', $zeile->id)
        ->assertSet('fehler', 'Wissens-Routings sind global — nur das Master-Team darf sie ändern.');

    expect(DB::table('foodalchemist_knowledge_routings')->where('id', $zeile->id)->exists())->toBeTrue();
});

it('sagt beim Entfernen, dass die Kategorie danach search-only ist', function () {
    // Zeile weg ≠ mode=none. Das eine ist „keine Route", das andere „bewusst leer" — der
    // Unterschied entscheidet, ob es ein Befund ist.
    config(['foodalchemist.prompts' => ['test.x' => ['tier' => 'B', 'task' => 'x']]]);
    ($this->mkRouting)('test.x', 'cross_cutting');
    $zeile = DB::table('foodalchemist_knowledge_routings')->where('feature', 'test.x')->first();

    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(Wissenssteuerung::class)
        ->call('delete', $zeile->id)
        ->assertSet('hinweis', fn ($h) => str_contains((string) $h, 'search-only'));
});

/**
 * ★ Der Versuch, die Lücke aus `feedback_fa_test_harness_layout_blind` zu schliessen — und
 * warum er nur zur Hälfte gelingt.
 *
 * `Livewire::test` rendert die Komponente OHNE Layout — ein 500er durch die Seitenhülle
 * (Sidebar, `x-ui-page`, Nav-Registrierung) bliebe dort grün. Dieser Request geht durch den
 * echten HTTP-Kernel und rendert die ganze Seite.
 *
 * Zusätzlich pinnt er, dass die Sektion in `Settings\Index::SEKTIONEN` steht: fehlt sie,
 * antwortet die Route mit 404 (`abort_unless`), und die Seite wäre unerreichbar, obwohl
 * Komponente und Blade existieren.
 *
 * **Warum er übersprungen wird.** Cores `layouts/app.blade.php` zieht bei jedem Ganzseiten-
 * Render eine Kette von Core-Tabellen. Drei davon habe ich in die Harness-Allowlist
 * nachgezogen (`user_ui_preferences`, `team_core_ai_models`, `team_invitations` — echte
 * Lücken, die jeden `->get(route(...))` mit 500 beantwortet hätten). Die Kette endet aber bei
 * `oauth_access_tokens`, und Passport-Tabellen gehören nicht in einen Modul-Test-Harness.
 *
 * Die Seite ist deshalb **gegen die Dev-MySQL** verifiziert (Status 200, 1,7 MB HTML, alle
 * erwarteten Inhalte) — genau der Weg, den `feedback_fa_test_harness_layout_blind` vorschreibt.
 * Der Test bleibt stehen: sobald der Harness Passport kennt, läuft er von selbst mit.
 */
it('rendert die Sektion als ganze Seite, nicht nur als Komponente', function () {
    config(['foodalchemist.prompts' => ['recipe.generator' => ['tier' => 'B', 'task' => 'x']]]);
    $this->actingAs($this->makeUser($this->rootTeam));

    $this->get(route('foodalchemist.einstellungen', ['sektion' => 'wissenssteuerung']))
        ->assertOk()
        ->assertSee('Wissens-Steuerung')
        ->assertSee('recipe.generator');
})->skip(
    fn () => ! \Illuminate\Support\Facades\Schema::hasTable('oauth_access_tokens'),
    'Ganzseiten-Render braucht Passport-Tabellen — Seite ist gegen Dev-MySQL verifiziert (Status 200)',
);

/*
 * ── KANON-EDITOR (Spec 52 · Paket 3) ─────────────────────────────────────────────────────
 *
 * Der Docblock der Komponente hatte ihn als „eigener Schritt" angekündigt: Routing ist
 * Zeilen-Pflege, der Kanon braucht eine Dossier-Suche. Seit F2 ist der Kanon aber die
 * EINZIGE Quelle für „muss in diesen Prompt" — ohne Editor hätte die abgeschaffte
 * Bindungs-Ebene weiterhin die einzige Oberfläche gehabt.
 */
it('nimmt ein Dossier in den Kanon eines Prompt-Keys auf', function () {
    config(['foodalchemist.prompts' => ['test.kanon' => ['tier' => 'B', 'task' => 'x']]]);
    ($this->mkDoc)('ui-regel-eins');
    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(Wissenssteuerung::class)
        ->call('kanonEdit', 'test.kanon')
        ->set('kanonForm.slug', 'ui-regel-eins')
        ->set('kanonForm.mode', 'pflicht')
        ->call('kanonAdd')
        ->assertSet('fehler', null);

    $zeile = DB::table('foodalchemist_knowledge_canon as c')
        ->join('foodalchemist_knowledge_documents as d', 'd.id', '=', 'c.knowledge_document_id')
        ->where('c.scope_key', 'test.kanon')->whereNull('c.deleted_at')
        ->first(['d.slug', 'c.mode', 'c.scope']);

    expect($zeile)->not->toBeNull()
        ->and($zeile->slug)->toBe('ui-regel-eins')
        ->and($zeile->mode)->toBe('pflicht')
        ->and($zeile->scope)->toBe('prompt_key');
});

it('schluckt die Hinweise des Service NICHT — ein inaktives Dossier liefert nichts', function () {
    // ★ Der Fall, der sonst still bliebe: die Zeile ENTSTEHT, sieht im Bericht nach
    // Versorgung aus und liefert nichts, weil `documentsFor()` inaktive filtert. Genau so ist
    // beim 155-Originale-Cutover Wissen verschwunden. Der Service warnt — die UI muss es zeigen.
    config(['foodalchemist.prompts' => ['test.kanon' => ['tier' => 'B', 'task' => 'x']]]);
    ($this->mkDoc)('ui-regel-inaktiv', aktiv: false);
    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(Wissenssteuerung::class)
        ->call('kanonEdit', 'test.kanon')
        ->set('kanonForm.slug', 'ui-regel-inaktiv')
        ->call('kanonAdd')
        ->assertSee('inaktiv');
});

it('geht über den Service, nicht per Insert — der Changelog-Guard greift auch in der UI', function () {
    // Der Wissens-Browser hatte in der Gegenrichtung genau diesen Fehler: `addBinding()` war
    // ein roher Insert an den Garantien des Service vorbei (Befund F). Hier NICHT wieder.
    config(['foodalchemist.prompts' => ['test.kanon' => ['tier' => 'B', 'task' => 'x']]]);
    ($this->mkDoc)('ui-regel-changelog', inhalt: "# Regel\n\n## Changelog\n- v1");
    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(Wissenssteuerung::class)
        ->call('kanonEdit', 'test.kanon')
        ->set('kanonForm.slug', 'ui-regel-changelog')
        ->call('kanonAdd')
        ->assertSet('hinweis', null);

    expect(DB::table('foodalchemist_knowledge_canon')->where('scope_key', 'test.kanon')->count())->toBe(0);
});

it('nimmt ein Dossier wieder aus dem Kanon', function () {
    config(['foodalchemist.prompts' => ['test.kanon' => ['tier' => 'B', 'task' => 'x']]]);
    ($this->mkDoc)('ui-regel-raus');
    $this->kanonZeile((int) $this->rootTeam->id, 'test.kanon', 'ui-regel-raus');
    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(Wissenssteuerung::class)
        ->call('kanonEdit', 'test.kanon')
        ->call('kanonRemove', 'test.kanon', 'ui-regel-raus');

    expect(DB::table('foodalchemist_knowledge_canon')->where('scope_key', 'test.kanon')
        ->whereNull('deleted_at')->count())->toBe(0);
});

it('faesst eine GLOBALE Kanon-Zeile nicht an — und sagt das, statt Erfolg zu melden', function () {
    // ★ Vom eigenen Test gefunden. `KnowledgeCanonService::remove()` fasst per Default nur
    // TEAM-Zeilen an; eine globale bleibt stehen. Die UI erzeugt selbst Team-Zeilen, der Fall
    // tritt also nur bei geerbtem Master-Kanon auf — genau dann darf die Meldung aber nicht
    // „entfernt" sagen. Ein stiller No-op mit Erfolgsmeldung wäre die Fehlerklasse dieser Spec.
    config(['foodalchemist.prompts' => ['test.kanon' => ['tier' => 'B', 'task' => 'x']]]);
    ($this->mkDoc)('ui-regel-global');
    $this->kanonZeile((int) $this->rootTeam->id, 'test.kanon', 'ui-regel-global');
    DB::table('foodalchemist_knowledge_canon')->where('scope_key', 'test.kanon')->update(['team_id' => null]);
    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(Wissenssteuerung::class)
        ->call('kanonEdit', 'test.kanon')
        ->call('kanonRemove', 'test.kanon', 'ui-regel-global')
        ->assertSee('Nichts entfernt');

    expect(DB::table('foodalchemist_knowledge_canon')->where('scope_key', 'test.kanon')
        ->whereNull('deleted_at')->count())->toBe(1);
});

it('sucht Dossiers erst ab zwei Zeichen — 1.100 Treffer sind kein Waehler', function () {
    ($this->mkDoc)('ui-suchtreffer');
    $this->actingAs($this->makeUser($this->rootTeam));

    Livewire::test(Wissenssteuerung::class)
        ->set('kanonSuche', 'u')
        ->assertViewHas('kanonTreffer', fn ($t) => $t->isEmpty())
        ->set('kanonSuche', 'ui-such')
        ->assertViewHas('kanonTreffer', fn ($t) => $t->contains('slug', 'ui-suchtreffer'));
});
