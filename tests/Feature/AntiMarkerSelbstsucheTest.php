<?php

use Platform\FoodAlchemist\Models\FoodAlchemistTerminologyAntiMarker;
use Platform\FoodAlchemist\Services\TerminologyService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * DER ANTI-MARKER FEUERTE GEGEN SICH SELBST.
 *
 * Die Regeln schützen vor Verwechslung («sherry ↛ sherryessig», «brie ↛ bries»), und die
 * Treffer-Prüfung ist bewusst Compound-bewusst — „Kalbsbries" enthält „bries", „Briekäse"
 * enthält „brie". Genau das schlug zurück: `tokenHit` findet „sherry" auch als PRÄFIX in
 * der ANFRAGE „Sherryessig". Damit galt die eigene Suche als Verwechslung.
 *
 * Live gemessen (demo, 2026-09-03): «Sherryessig: konserviert» stand mit Score 1.001 im
 * Kandidaten-Pool und wurde von `stripAntiMarkers` entfernt — die Zutat blieb im erzeugten
 * Rezept ungemappt, obwohl das Grundprodukt seit dem 11. Juni existiert und vier
 * Lieferantenartikel dazu vorliegen.
 *
 * Der Riegel: wer den verbotenen Begriff SELBST sucht, verwechselt nichts.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    // Die Regeln sind Konstanten-Basis PLUS DB-Tabelle. «sherry ↛ sherryessig» lebt auf
    // demo in der Tabelle, nicht im Code — ohne dieses Seed würde der Test eine Regel
    // prüfen, die im Fixture gar nicht existiert (selbst hineingelaufen).
    FoodAlchemistTerminologyAntiMarker::create([
        'trigger_token' => 'sherry', 'forbid_token' => 'sherryessig',
    ]);
    app()->forgetInstance(TerminologyService::class);
    $this->t = app(TerminologyService::class);
});

it('die eigene Suche gilt nicht als Verwechslung (Sherryessig darf Sherryessig finden)', function () {
    expect($this->t->isAntiMarker('Sherryessig', 'Sherryessig: konserviert'))->toBeFalse();
});

it('der Schutz bleibt, wo er hingehört (Sherry darf NICHT auf Sherryessig matchen)', function () {
    // Das ist die Regel in ihrer gemeinten Richtung — sie muss weiter feuern.
    expect($this->t->isAntiMarker('Sherry', 'Sherryessig: konserviert'))->toBeTrue();
});

/*
 * AUSSAGEWERT: dieser Test ist auch OHNE den Riegel grün — die Brie/Bries-Regel feuert im
 * Fixture nicht so wie die geseedete Sherry-Regel. Er bleibt als Verhaltens-Zusicherung
 * stehen; NACHGEWIESEN wird der Defekt vom ersten Test (ohne Riegel: „Failed asserting
 * that true is false").
 */
it('Gegenrichtung: wer Bries sucht, bekommt Bries', function () {
    expect($this->t->isAntiMarker('Bries', 'Bries: frisch'))->toBeFalse();
});

it('und der klassische Fall bleibt geschützt (Brie ↛ Bries)', function () {
    expect($this->t->isAntiMarker('Brie', 'Bries: frisch'))->toBeTrue();
});

it('unbeteiligte Paare bleiben unbeteiligt', function () {
    expect($this->t->isAntiMarker('Weissweinessig', 'Weissweinessig: konserviert'))->toBeFalse()
        ->and($this->t->isAntiMarker('Tomate', 'Tomate: frisch'))->toBeFalse();
});
