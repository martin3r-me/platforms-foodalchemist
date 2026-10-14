<?php

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistFormat;
use Platform\FoodAlchemist\Models\FoodAlchemistPaket;
use Platform\FoodAlchemist\Support\TeamAncestryRegistry;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 82 Abo für Konzepte/Pakete/Formate, PR 1: die gemeinsame Fassungs-Basis. Fassungs-Zeilen (ist_fassung = 1)
 * tauchen bei Konzept, Paket und Format in keiner normalen Abfrage auf (wie beim Rezept) und sind nur mit Absicht
 * lesbar; Verweise (Slots) lösen sie trotzdem auf.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->master = Team::create(['name' => 'BHG.DIGITAL (Master)', 'user_id' => 1, 'personal_team' => false]);
    config(['foodalchemist.master_team_id' => $this->master->id]);
    $this->broich = Team::create(['name' => 'Broich', 'user_id' => 1, 'personal_team' => false]);
    TeamAncestryRegistry::flushAll();

    $this->arten = [
        'concept' => fn (array $a) => FoodAlchemistConcept::create($a + ['team_id' => $this->master->id]),
        'paket' => fn (array $a) => FoodAlchemistPaket::create($a + ['team_id' => $this->master->id]),
        'format' => fn (array $a) => FoodAlchemistFormat::create($a + ['team_id' => $this->master->id]),
    ];
    $this->klassen = ['concept' => FoodAlchemistConcept::class, 'paket' => FoodAlchemistPaket::class, 'format' => FoodAlchemistFormat::class];
});

dataset('arten', ['concept', 'paket', 'format']);

it('Fassung ist in keiner normalen Abfrage, mitFassungen() findet sie · :dataset', function (string $art) {
    $klasse = $this->klassen[$art];
    $arbeit = ($this->arten[$art])(['name' => 'Grill-Abend']);
    $fassung = ($this->arten[$art])(['name' => 'Grill-Abend', 'ist_fassung' => true, 'fassung_von_id' => $arbeit->id, 'fassung_nr' => 1,
        'inhalt_hash' => str_repeat('a', 64), 'aenderungsnotiz' => 'Erst']);

    expect($klasse::query()->where('name', 'Grill-Abend')->pluck('id')->all())->toBe([$arbeit->id])
        ->and($klasse::where('team_id', $this->master->id)->count())->toBe(1)
        ->and($klasse::find($fassung->id))->toBeNull()
        ->and($klasse::mitFassungen()->find($fassung->id))->not->toBeNull()
        ->and($klasse::mitFassungen()->find($fassung->id)->istFassung())->toBeTrue()
        ->and($klasse::mitFassungen()->find($fassung->id)->fassung_allergen_geaendert)->toBeFalse();
})->with('arten');

it('Sichtbarkeit: Master sieht nur die Arbeitszeile, ein Mandant ohne Abo nichts · :dataset', function (string $art) {
    $klasse = $this->klassen[$art];
    $arbeit = ($this->arten[$art])(['name' => 'Sicht-Probe']);
    $fassung = ($this->arten[$art])(['name' => 'Sicht-Probe', 'ist_fassung' => true, 'fassung_von_id' => $arbeit->id, 'fassung_nr' => 1]);
    $sieht = fn (Team $t) => $klasse::visibleToTeam($t)->where('name', 'Sicht-Probe')
        ->pluck((new $klasse)->getTable() . '.id')->map(fn ($v) => (int) $v)->all();

    expect($sieht($this->master))->toBe([$arbeit->id])
        ->and($sieht($this->master))->not->toContain($fassung->id)
        ->and($sieht($this->broich))->toBe([]);
})->with('arten');

it('fassungen() liefert die Fassungen der Arbeitszeile, neueste zuerst · :dataset', function (string $art) {
    $arbeit = ($this->arten[$art])(['name' => 'Verlauf']);
    foreach ([1, 2, 3] as $nr) {
        ($this->arten[$art])(['name' => 'Verlauf', 'ist_fassung' => true, 'fassung_von_id' => $arbeit->id, 'fassung_nr' => $nr]);
    }
    ($this->arten[$art])(['name' => 'Fremd', 'ist_fassung' => true, 'fassung_von_id' => 999999, 'fassung_nr' => 1]);

    expect($arbeit->fassungen()->pluck('fassung_nr')->map(fn ($v) => (int) $v)->all())->toBe([3, 2, 1]);
})->with('arten');

it('Verweise lösen Fassungen auf: Slot zeigt auf Konzept- und Paket-Fassung', function () {
    $konzept = ($this->arten['concept'])(['name' => 'Menü']);
    $kFassung = ($this->arten['concept'])(['name' => 'Menü', 'ist_fassung' => true, 'fassung_von_id' => $konzept->id, 'fassung_nr' => 1]);
    $paket = ($this->arten['paket'])(['name' => 'Salad Wall']);
    $pFassung = ($this->arten['paket'])(['name' => 'Salad Wall', 'ist_fassung' => true, 'fassung_von_id' => $paket->id, 'fassung_nr' => 1]);
    $eingebettet = ($this->arten['concept'])(['name' => 'Paket-Konzept', 'kind' => 'paket']);
    $eFassung = ($this->arten['concept'])(['name' => 'Paket-Konzept', 'kind' => 'paket', 'ist_fassung' => true, 'fassung_von_id' => $eingebettet->id, 'fassung_nr' => 1]);

    $slot = FoodAlchemistConceptSlot::create(['team_id' => $this->master->id, 'concept_id' => $kFassung->id, 'position' => 1,
        'package_id' => $pFassung->id, 'embedded_concept_id' => $eFassung->id]);
    $slot = FoodAlchemistConceptSlot::find($slot->id);

    expect($slot->concept?->id)->toBe($kFassung->id)
        ->and($slot->package?->id)->toBe($pFassung->id)
        ->and($slot->embeddedConcept?->id)->toBe($eFassung->id);
});

it('Scope-Merker gilt je Tabelle: Rezept-, Konzept-, Paket-, Format-Abfragen sind unabhängig vom ersten Aufruf', function () {
    foreach (['paket', 'format', 'concept'] as $art) {
        $klasse = $this->klassen[$art];
        $a = ($this->arten[$art])(['name' => 'Reihenfolge']);
        ($this->arten[$art])(['name' => 'Reihenfolge', 'ist_fassung' => true, 'fassung_von_id' => $a->id, 'fassung_nr' => 1]);
        expect($klasse::where('name', 'Reihenfolge')->count())->toBe(1);
    }
});
