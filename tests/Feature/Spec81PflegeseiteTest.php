<?php

use Livewire\Livewire;
use Platform\FoodAlchemist\Livewire\Settings\Regeln;
use Platform\FoodAlchemist\Models\FoodAlchemistRule;
use Platform\FoodAlchemist\Services\Regeln\RegelBuch;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/** Spec 81 Teil G — Einstellungen › Regeln: Liste, Editor je Art, Probelauf-Pflicht, Aktivieren, Zuordnung ohne Datenverlust. */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $admin = $this->makeUser($this->rootTeam);
    config(['platform-shell.admins' => [strtolower((string) $admin->email)]]);   // Regeln = Plattform-Admin
    $this->actingAs($admin);
    $this->regel = fn (string $k) => FoodAlchemistRule::where('schluessel', $k)->firstOrFail();
});

it('listet die Seed-Regeln nach Regelwerk und öffnet den Editor mit allen Zeilen', function () {
    $zuo = ($this->regel)('basisrezept.5.default_gp');

    Livewire::test(Regeln::class)
        ->assertSee('Typ-Vokabular')->assertSee('Default-Grundprodukte')->assertSee('Verpackungswort im GP-Namen')
        ->call('oeffne', $zuo->id)
        ->assertSeeHtml('data-regel-editor="basisrezept.5.default_gp"')
        ->assertSet('form.zeilen', fn ($z) => count($z) === count($zuo->params['eintraege']));
});

it('aktive Regel: Speichern verlangt erst den Probelauf, danach neue Version', function () {
    $r = ($this->regel)('gp.7.1.gebinde');

    $c = Livewire::test(Regeln::class)->call('oeffne', $r->id)
        ->set('form.tokens', "Kiste\nKarton\nDose\nEimer")
        ->call('speichern')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Probelauf'))
        ->assertSet('probelauf', fn ($p) => is_array($p) && $p['bestand'] === 'Grundprodukte (Name)');
    expect($r->fresh()->version)->toBe(1);

    $c->call('speichern')->assertSet('fehler', null);
    expect($r->fresh()->version)->toBe(2)->and($r->fresh()->params['tokens'])->toBe(['Kiste', 'Karton', 'Dose', 'Eimer']);
});

it('Zuordnung: Speichern behält Kontext und Wortmengen-Optionen der Einträge', function () {
    $r = ($this->regel)('basisrezept.5.default_gp');

    Livewire::test(Regeln::class)->call('oeffne', $r->id)->call('pruefeProbelauf')->call('speichern')->assertSet('fehler', null);

    $eintraege = collect($r->fresh()->params['eintraege']);
    expect($eintraege->firstWhere('ziel_name', 'Tomaten: frisch, ganz')['kontext'])->toBe(['prefer_raw' => ['ja']])
        ->and($eintraege->firstWhere('begriff', 'pfeffer')['erlaubt'])->toBe(['schwarz', 'schwarzer', 'ganz', 'gemahlen'])
        ->and($r->fresh()->params['vergleich'])->toBe('tokens');
});

it('ungültige Beispiele blockieren das Speichern', function () {
    $r = ($this->regel)('gp.10.generik');

    Livewire::test(Regeln::class)->call('oeffne', $r->id)
        ->set('form.richtig', 'Apfel (generisch): frisch')
        ->call('pruefeProbelauf')->call('speichern')
        ->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Beispiel (richtig) stimmt nicht'));
    expect($r->fresh()->version)->toBe(1);
});

it('Team-Admins und Kunden sehen die Seite nicht', function () {
    $this->actingAs($this->makeUser($this->rootTeam, 'Team-Admin'));

    Livewire::test(Regeln::class)->assertStatus(404);
    expect(array_key_exists('regeln', Livewire::test(\Platform\FoodAlchemist\Livewire\Settings\Index::class)->viewData('sektionen')))->toBeFalse();
});

it('Ausschalten wirkt sofort im Regelbuch', function () {
    $r = ($this->regel)('gp.10.generik');

    Livewire::test(Regeln::class)->call('setzeAktiv', $r->id, false);

    expect(RegelBuch::falls('gp.10.generik'))->toBeNull();
});
