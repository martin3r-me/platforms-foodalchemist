<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Livewire\Controlling\Cockpit;
use Platform\FoodAlchemist\Livewire\Controlling\Concerns\ControllingTeam;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Erfolg;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Kennzahlen;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\LinkPreise;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Preisvergleich;
use Platform\FoodAlchemist\Livewire\Controlling\Panels\Wareneinsatz;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamBereich;
use Platform\FoodAlchemist\Services\SalesImportService;
use Platform\FoodAlchemist\Services\TeamSettingsService;
use Platform\FoodAlchemist\Support\FaTeam;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 85 Controlling PR-C (Dominique 19.10.): EINE Grundlage für alle Controlling-Teile (FaTeam::aktuell statt currentTeamRelation) und Rollen an
 * jeder Schreibaktion. Kontext wie auf io, Stufe 4: Master, Mandant (rootTeam) mit Standort (childA); im Mandanten ein Inhaber (Admin), ein Mitglied
 * (Kuratieren) und ein Betrachter (Lesen).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'Master', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id, 'foodalchemist.team_sicht.stufe' => 4]);
    TeamAncestryRegistry::flushAll();

    $this->inhaber = $this->makeUser($this->rootTeam, 'Mandant Inhaber', 'owner');
    $this->mitglied = $this->makeUser($this->rootTeam, 'Mandant Mitglied', 'member');
    $this->leser = $this->makeUser($this->rootTeam, 'Mandant Leser', 'viewer');
    $this->rolleFehlt = fn ($h) => str_contains((string) $h, 'mindestens die Rolle');
});

it('Wächter: jeder Controlling-Teil nutzt die gemeinsame Grundlage und liest nie currentTeamRelation', function () {
    $dateien = array_merge([Cockpit::class], array_map(fn ($f) => 'Platform\\FoodAlchemist\\Livewire\\Controlling\\Panels\\'.basename($f, '.php'),
        glob(dirname(__DIR__, 2).'/src/Livewire/Controlling/Panels/*.php')));

    expect(count($dateien))->toBeGreaterThanOrEqual(11);
    foreach ($dateien as $klasse) {
        expect(class_uses($klasse))->toHaveKey(ControllingTeam::class);   // {$klasse}
        expect(file_get_contents((new ReflectionClass($klasse))->getFileName()))->not->toContain('currentTeamRelation');
    }
});

it('betrachtendes Team = FaTeam::aktuell: im Standort-Kontext schreibt „Ziele speichern“ an den Standort, nicht an den Mandanten', function () {
    $this->actingAs($this->inhaber);
    $settings = app(TeamSettingsService::class);
    $vorher = $settings->zielWareneinsatzPct($this->rootTeam);

    FaTeam::imTeam($this->childA, function () {
        Livewire::test(Kennzahlen::class)->set('zielWe', '23')->set('marge', '150')->call('zieleSpeichern')->assertSet('meldung', 'Zielwerte gespeichert.');
    });

    expect($settings->zielWareneinsatzPct($this->childA))->toBe(23.0)
        ->and($settings->zielWareneinsatzPct($this->rootTeam))->toBe($vorher);   // Gegenprobe: der Mandant bleibt unverändert
});

it('Kennzahlen: Zielwerte speichern nur als Admin', function () {
    $this->actingAs($this->mitglied);
    Livewire::test(Kennzahlen::class)->set('zielWe', '30')->set('marge', '100')->call('zieleSpeichern')
        ->assertSet('meldung', fn ($m) => ($this->rolleFehlt)($m));

    $this->actingAs($this->inhaber);
    Livewire::test(Kennzahlen::class)->set('zielWe', '30')->set('marge', '100')->call('zieleSpeichern')->assertSet('meldung', 'Zielwerte gespeichert.');
});

it('Erfolg: Hochladen, scharfer Import und Zuordnen brauchen Kuratieren; der Probelauf schreibt nichts und bleibt für Betrachter offen', function () {
    Storage::fake();
    $datei = fn () => UploadedFile::fake()->createWithContent('umsatz.csv', "gericht;menge\nSuppe;3\n");

    $this->actingAs($this->leser);
    Livewire::test(Erfolg::class)->set('datei', $datei())->call('hochladen')->assertSet('fehler', fn ($f) => ($this->rolleFehlt)($f));
    expect(Storage::exists(SalesImportService::ordnerFuer($this->rootTeam).'/umsatz.csv'))->toBeFalse();

    Livewire::test(Erfolg::class)->set('dateiname', 'umsatz.csv')->call('scharf')->assertSet('fehler', fn ($f) => ($this->rolleFehlt)($f));
    Livewire::test(Erfolg::class)->call('zuordnenOeffnen', 1)->call('zuordnen', 1)->assertSet('fehler', fn ($f) => ($this->rolleFehlt)($f))->assertSet('zuordnenId', null);
    // Probelauf: keine Rollenmeldung (Lesen genügt; ohne Datei meldet der Import etwas anderes)
    Livewire::test(Erfolg::class)->set('dateiname', 'fehlt.csv')->call('trockenlauf')->assertSet('fehler', fn ($f) => ! ($this->rolleFehlt)($f));

    // Gegenprobe: das Mitglied darf hochladen und übernehmen
    $this->actingAs($this->mitglied);
    Livewire::test(Erfolg::class)->set('datei', $datei())->call('hochladen')->assertSet('fehler', fn ($f) => ! ($this->rolleFehlt)($f));
    expect(Storage::exists(SalesImportService::ordnerFuer($this->rootTeam).'/umsatz.csv'))->toBeTrue();
    Livewire::test(Erfolg::class)->set('dateiname', 'umsatz.csv')->call('scharf')->assertSet('fehler', fn ($f) => ! ($this->rolleFehlt)($f));
});

it('Preisvergleich: Bestellzeile braucht das Einkaufs-Recht (Bereich Einkauf + Kuratieren), Bezugsquelle festlegen braucht Kuratieren', function () {
    $this->actingAs($this->leser);
    Livewire::test(Preisvergleich::class)->call('uebernehmen', 999999)->assertSet('fehler', fn ($f) => ($this->rolleFehlt)($f));
    Livewire::test(Preisvergleich::class)->call('bezugsquelleSetzen', 1, 1)->assertSet('fehler', fn ($f) => ($this->rolleFehlt)($f));

    // Mitglied: Rolle reicht, der Artikel gibt es nicht → andere Meldung (die Rolle ist nicht das Problem)
    $this->actingAs($this->mitglied);
    Livewire::test(Preisvergleich::class)->call('uebernehmen', 999999)->assertSet('fehler', fn ($f) => $f !== null && ! ($this->rolleFehlt)($f));

    // Bereich Einkauf für den Mandanten abgeschaltet: auch ein Mitglied darf nicht in die Bestellung übernehmen
    FoodAlchemistTeamBereich::create(['team_id' => $this->rootTeam->id, 'bereich' => 'einkauf', 'aktiv' => false]);
    Livewire::test(Preisvergleich::class)->call('uebernehmen', 999999)->assertSet('fehler', fn ($f) => str_contains((string) $f, 'Bereich Einkauf'));
});

it('Wareneinsatz-Batch „umstellen“ und Link-Prüfung brauchen Kuratieren', function () {
    $this->actingAs($this->leser);
    Livewire::test(Wareneinsatz::class)->set('auswahl', [1])->call('umstellen')->assertSet('fehler', fn ($f) => ($this->rolleFehlt)($f));
    Livewire::test(Wareneinsatz::class)->set('auswahl', [1])->call('vorschau')->assertSet('fehler', fn ($f) => ($this->rolleFehlt)($f));
    Livewire::test(LinkPreise::class)->call('jetztPruefen')->assertSet('hinweis', fn ($h) => ($this->rolleFehlt)($h));

    $this->actingAs($this->mitglied);
    Livewire::test(Wareneinsatz::class)->set('auswahl', [])->call('umstellen')->assertSet('fehler', 'Nichts markiert.');   // Rolle ok, nur nichts gewählt
    Livewire::test(LinkPreise::class)->call('jetztPruefen')->assertSet('hinweis', fn ($h) => str_starts_with((string) $h, 'Geprüft'));
});
