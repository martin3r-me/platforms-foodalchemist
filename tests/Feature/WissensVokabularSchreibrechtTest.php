<?php

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Einsatzorte;
use Platform\FoodAlchemist\Livewire\Settings\Wissenskategorien;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Die ANDERE Hälfte des Wissens-Modells. Der Korpus wird gemeinsam GELESEN
 * (WissenKorpusGemeinsamTest) — geschrieben wird nur im Eigentum.
 *
 * Befund aus dem Voll-Audit 2026-09-02: `Wissenskategorien::save()/toggleActive()` und
 * ALLE Schreibwege in `Einsatzorte` liefen ohne jede Eigentumsprüfung, obwohl
 * `Wissenskategorien::delete()` in derselben Klasse das Modell ausführlich führt
 * (eigene Zeile immer, global nur als Master, fremde nie). Die Regel war auf halber
 * Strecke stehengeblieben.
 *
 * Warum es zählt: `toggleActive(int $id)` nimmt die ID DIREKT vom Client, und `editId`
 * ist eine Livewire-Property — also ebenfalls setzbar. Ein Layer ist ein Bindungs-ZIEL:
 * abgeschaltet verliert jeder daran gebundene Prompt sein Wissen. Ein Kind-Team konnte
 * damit das Vokabular aller Teams umbenennen oder die KI-Versorgung stilllegen.
 */
function vokKategorie(?int $teamId, string $slug, string $label): int
{
    return DB::table('foodalchemist_knowledge_categories')->insertGetId([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId, 'slug' => $slug,
        'label' => $label, 'description' => null, 'sort_order' => 0, 'active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function vokLayer(?int $teamId, string $slug, string $label): int
{
    return DB::table('foodalchemist_knowledge_layers')->insertGetId([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $teamId, 'slug' => $slug,
        'label' => $label, 'description' => null, 'kind' => 'prompt', 'sort_order' => 0,
        'active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

beforeEach(function () {
    $this->seedTeamHierarchy();
    // childA ist NICHT Master (hat ein Eltern-Team) — global bleibt für ihn tabu.
    $this->actingAs($this->makeUser($this->childA, 'Kind A'));
});

it('Kategorie: fremde Zeile lässt sich weder umbenennen noch abschalten', function () {
    $fremd = vokKategorie($this->childB->id, 'fremd_kat', 'Fremde Kategorie');

    Livewire::test(Wissenskategorien::class)
        ->set('editId', $fremd)                                      // editId ist client-setzbar
        ->set('form', ['label' => 'Gekapert', 'description' => '', 'sort_order' => 0])
        ->call('save')
        ->assertSet('fehler', fn ($f) => $f !== null);

    expect(DB::table('foodalchemist_knowledge_categories')->where('id', $fremd)->value('label'))
        ->toBe('Fremde Kategorie');

    Livewire::test(Wissenskategorien::class)->call('toggleActive', $fremd);
    expect((bool) DB::table('foodalchemist_knowledge_categories')->where('id', $fremd)->value('active'))->toBeTrue();
});

it('Kategorie: globales Vokabular ist für ein Nicht-Master-Team tabu', function () {
    $global = vokKategorie(null, 'global_kat', 'Globale Kategorie');

    Livewire::test(Wissenskategorien::class)->call('toggleActive', $global);

    expect((bool) DB::table('foodalchemist_knowledge_categories')->where('id', $global)->value('active'))->toBeTrue();
});

it('Kategorie: die EIGENE Zeile bleibt pflegbar — der Riegel darf nicht überschiessen', function () {
    $eigen = vokKategorie($this->childA->id, 'eigen_kat', 'Eigene Kategorie');

    Livewire::test(Wissenskategorien::class)
        ->set('editId', $eigen)
        ->set('form', ['label' => 'Umbenannt', 'description' => '', 'sort_order' => 0])
        ->call('save');

    expect(DB::table('foodalchemist_knowledge_categories')->where('id', $eigen)->value('label'))->toBe('Umbenannt');
});

it('Einsatzort: fremder Layer lässt sich nicht abschalten — sonst fällt gebundenes Wissen aus', function () {
    $fremd = vokLayer($this->childB->id, 'fremd.layer', 'Fremder Einsatzort');

    Livewire::test(Einsatzorte::class)
        ->call('toggleActive', $fremd)
        ->assertSet('fehler', fn ($f) => $f !== null);

    expect((bool) DB::table('foodalchemist_knowledge_layers')->where('id', $fremd)->value('active'))->toBeTrue();
});

it('Einsatzort: edit() lädt keine fremde Zeile in das Formular', function () {
    $fremd = vokLayer($this->childB->id, 'fremd.layer2', 'Fremder Einsatzort 2');

    Livewire::test(Einsatzorte::class)
        ->call('edit', $fremd)
        ->assertSet('editId', null)
        ->assertSet('fehler', fn ($f) => $f !== null);
});

it('Einsatzort: save() prüft ERNEUT — editId allein ist kein Nachweis', function () {
    $fremd = vokLayer($this->childB->id, 'fremd.layer3', 'Fremder Einsatzort 3');

    Livewire::test(Einsatzorte::class)
        ->set('editId', $fremd)                                      // Guard aus edit() übersprungen
        ->set('form', ['label' => 'Gekapert', 'description' => ''])
        ->call('save')
        ->assertSet('fehler', fn ($f) => $f !== null);

    expect(DB::table('foodalchemist_knowledge_layers')->where('id', $fremd)->value('label'))
        ->toBe('Fremder Einsatzort 3');
});

it('Einsatzort: der eigene Layer bleibt pflegbar', function () {
    $eigen = vokLayer($this->childA->id, 'eigen.layer', 'Eigener Einsatzort');

    Livewire::test(Einsatzorte::class)->call('toggleActive', $eigen);

    expect((bool) DB::table('foodalchemist_knowledge_layers')->where('id', $eigen)->value('active'))->toBeFalse();
});
