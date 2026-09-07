<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Wissenssteuerung;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

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
