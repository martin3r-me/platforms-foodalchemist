<?php

use Platform\FoodAlchemist\Support\MengeAusBezeichnung;

/**
 * Mengen-Korrektur aus der Bezeichnung: echte Artikel aus dem io-Export vom 16.10.2026 (la_mengen.csv), Felder
 * [Bezeichnung, qty, Stück je Gebinde, Einheit].
 */
dataset('korrigiert', [
    'Apfelsaft 0,2 / 24' => ['0,2 APFELSAFT KLAR MW', 0.008, 24, 'l', 0.2],
    'Flensburger 0,33 / 20' => ['0,33 FLENSBURGER ALKOHOLFREI MW', 0.017, 20, 'l', 0.33],
    'Orangina 0,25 / 15' => ['0,25 ORANGINA MW', 0.017, 15, 'l', 0.25],
    'Schweppes 1,0 / 6' => ['1,0 SCHWEPPES DRY TONIC PET MW', 0.167, 6, 'l', 1.0],
    'Oktoberfest 0,5L / 20' => ['0,5L HB OKTOBERFEST BIER MW', 0.025, 20, 'l', 0.5],
    'Heineken 0,25 / 28' => ['0,25 HEINEKEN PILS MW', 0.009, 28, 'l', 0.25],
    'Shot 0,02L / 10' => ['0,02L DILDO SHOT VODKA DRINK 10%', 0.002, 10, 'l', 0.02],
]);

dataset('bleibt', [
    'Broscheit-Kiste: Bestelleinheit 1 kg, Gebinde 2 kg' => ['2KG KISTE KENIABOHEN LOSE', 1.0, 2, 'kg'],
    'Broscheit 5 KG Eimer' => ['5 KG EIMER IBIZA FRUCHTSALAT (83)', 1.0, 5, 'kg'],
    'Majoran 7,5G nur gerundet' => ['7,5G MAJORAN GEREBELT         OST', 0.008, 1, 'kg'],
    'Gewürz 40G korrekt' => ['40G GEWUERZ', 0.04, 10, 'kg'],
    'Abtropfgewicht (passt nicht)' => ['720G SARDELLENFILETS IN SONNENBLUMENOEL', 0.7, 1, 'kg'],
    'Bonuspackung (passt nicht)' => ['200G FERRERO SCHOKOBONS + 25G', 0.225, 1, 'kg'],
    'Gebinde statt Einzel (passt nicht)' => ['60G SPROSSENTRIO', 0.48, 1, 'kg'],
    'Einheit Stk' => ['0,33 BIONADE KRAEUTER MW', 1.0, 12, 'Stk'],
    'Einheit widerspricht' => ['0,5L SAUCE', 0.025, 20, 'kg'],
    'keine Menge in der Bezeichnung' => ['Maishendlfilet 180g lose fr. AMA 1 VE = 5 kg', 1.0, 5, 'kg'],
    'ohne Einheit' => ['0,2 APFELSAFT KLAR MW', 0.008, 24, null],
]);

it('korrigiert die EDI-Teilung auf die Menge aus der Bezeichnung', function (string $bez, float $qty, int $n, string $e, float $soll) {
    expect(MengeAusBezeichnung::korrektur($bez, $qty, $n, $e))->toBe($soll);
})->with('korrigiert');

it('lässt korrekte, gerundete und andere Abweichungen unangetastet', function (string $bez, float $qty, int $n, ?string $e) {
    expect(MengeAusBezeichnung::korrektur($bez, $qty, $n, $e))->toBeNull();
})->with('bleibt');
