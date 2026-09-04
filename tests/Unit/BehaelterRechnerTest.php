<?php

use Platform\FoodAlchemist\Services\BehaelterRechner;

/**
 * Spec 51 — produzierte Menge (kg) → ganze Behälter, plus Alternativen.
 *
 * Pure Unit-Tests, Behälter duck-typed. Die Maße sind ECHTE Werte nach EuroNorm 631-1
 * (GN 1/1 = 530 × 325 mm, 65 mm ≙ 9,0 l Nennvolumen) — keine erfundene Fixture, die sich
 * ihren eigenen Vertrag baut.
 */
beforeEach(function () {
    $this->r = new BehaelterRechner;

    $this->gn = fn (int $id, string $name, float $l, float $b, float $t, float $vol, ?array $eignung = null) => (object) [
        'id' => $id, 'name' => $name,
        'laenge_mm' => $l, 'breite_mm' => $b, 'tiefe_mm' => $t, 'volumen_l' => $vol,
        'nutzfaktor' => 0.85, 'max_fuellgewicht_kg' => 15.0, 'kapazitaet_kg' => null,
        'eignung' => $eignung,
    ];

    $this->gn11_65 = ($this->gn)(1, 'GN 1/1 65mm', 530, 325, 65, 9.0);
    $this->gn11_100 = ($this->gn)(2, 'GN 1/1 100mm', 530, 325, 100, 14.0);
    $this->gn11_200 = ($this->gn)(3, 'GN 1/1 200mm', 530, 325, 200, 28.0);
    $this->gn12_65 = ($this->gn)(4, 'GN 1/2 65mm', 325, 265, 65, 4.0);
    $this->gn16_100 = ($this->gn)(5, 'GN 1/6 100mm', 176, 162, 100, 1.6);

    $this->eimer = (object) [
        'id' => 9, 'name' => 'Eimer 10 l',
        'laenge_mm' => null, 'breite_mm' => null, 'tiefe_mm' => null, 'volumen_l' => 10.0,
        'nutzfaktor' => 0.90, 'max_fuellgewicht_kg' => 10.0, 'kapazitaet_kg' => null,
        'eignung' => ['abfuellen', 'transport'],
    ];

    $this->basis = fn (array $a) => array_merge([
        'container' => $this->gn11_65, 'referenz_menge_kg' => null, 'dichteklasse' => null,
        'skalierung' => 'hoehe_gebunden', 'max_schichthoehe_mm' => null, 'konfidenz_rang3' => false,
    ], $a);
});

it('10 kg auf eine 8-kg-Referenz ergibt zwei flache GN — der Fall aus der Küche', function () {
    $out = $this->r->varianten(10.0, ($this->basis)(['referenz_menge_kg' => 8.0]), [], 'regenerieren');

    expect($out['berechenbar'])->toBeTrue()
        ->and($out['varianten'][0]['behaelter'])->toBe('GN 1/1 65mm')
        ->and($out['varianten'][0]['anzahl'])->toBe(2)
        ->and($out['varianten'][0]['konfidenz'])->toBe('hoch')
        ->and($out['varianten'][0]['rest_im_letzten_kg'])->toBe(6.0);
});

it('2 kg passen in EINEN kleineren Einsatz — die Menge wählt die Größe', function () {
    $out = $this->r->varianten(
        2.0,
        ($this->basis)(['referenz_menge_kg' => 8.0]),
        [$this->gn12_65, $this->gn16_100],
        'regenerieren'
    );

    $nachName = collect($out['varianten'])->keyBy('behaelter');

    // GN 1/2-65 fasst 44 % des GN 1/1-65 (nicht 50 % — die kleinere Form verliert mehr an Wand):
    // 4,0 l / 9,0 l × 8 kg = 3,556.
    expect($nachName['GN 1/2 65mm']['kg_je_behaelter'])->toBe(3.556)
        ->and($nachName['GN 1/2 65mm']['anzahl'])->toBe(1);
});

it('exaktes Vielfaches rundet NICHT hoch (Epsilon-Guard)', function () {
    $out = $this->r->varianten(16.0, ($this->basis)(['referenz_menge_kg' => 8.0]), [], 'regenerieren');

    expect($out['varianten'][0]['anzahl'])->toBe(2)
        ->and($out['varianten'][0]['rest_im_letzten_kg'])->toBe(0.0);
});

it('ohne Menge kommt ein Grund statt einer Zahl', function () {
    $out = $this->r->varianten(0.0, ($this->basis)(['referenz_menge_kg' => 8.0]), [], 'regenerieren');

    expect($out['berechenbar'])->toBeFalse()
        ->and($out['varianten'])->toBe([])
        ->and($out['grund'])->toContain('yield_kg');
});

it('ohne Referenz und ohne Dichteklasse wird nicht geraten', function () {
    $ohne = clone $this->gn11_65;
    $ohne->volumen_l = null;
    $ohne->laenge_mm = null;
    $ohne->breite_mm = null;

    $out = $this->r->varianten(10.0, ($this->basis)(['container' => $ohne]), [], 'regenerieren');

    expect($out['berechenbar'])->toBeFalse()
        ->and($out['grund'])->toContain('Dichteklasse');
});

it('Dichteklasse trägt als Rang 2 — mit abgestufter Konfidenz', function () {
    $out = $this->r->varianten(
        20.0,
        ($this->basis)(['container' => $this->gn11_100, 'dichteklasse' => 'schuettfaehig']),
        [],
        'regenerieren'
    );

    // 14,0 l Nennvolumen × 0,85 Nutzfaktor × 0,6 kg/l = 7,14 kg je Behälter.
    expect($out['varianten'][0]['kg_je_behaelter'])->toBe(7.14)
        ->and($out['varianten'][0]['anzahl'])->toBe(3)
        ->and($out['varianten'][0]['konfidenz'])->toBe('mittel');
});

it('Warengruppen-Default stuft die Konfidenz auf niedrig', function () {
    $out = $this->r->varianten(
        20.0,
        ($this->basis)(['container' => $this->gn11_100, 'dichteklasse' => 'schuettfaehig', 'konfidenz_rang3' => true]),
        [],
        'regenerieren'
    );

    expect($out['varianten'][0]['konfidenz'])->toBe('niedrig');
});

it('Behälter ohne Maße fällt sauber auf sein Nennvolumen zurück (Eimer)', function () {
    $out = $this->r->varianten(
        40.0,
        ['container' => $this->eimer, 'referenz_menge_kg' => null, 'dichteklasse' => 'fluessig',
            'skalierung' => 'tiefer_fuellbar', 'max_schichthoehe_mm' => null, 'konfidenz_rang3' => false],
        [],
        'abfuellen'
    );

    // 10 l × 0,90 = 9 kg je Eimer → 40 kg brauchen fünf, nicht vier.
    expect($out['berechenbar'])->toBeTrue()
        ->and($out['varianten'][0]['kg_je_behaelter'])->toBe(9.0)
        ->and($out['varianten'][0]['anzahl'])->toBe(5);
});

it('hoehe_gebunden: tiefer bringt nichts, flacher kappt und stuft ab', function () {
    $out = $this->r->varianten(
        10.0,
        ($this->basis)(['container' => $this->gn11_100, 'referenz_menge_kg' => 12.0]),
        [$this->gn11_65],
        'regenerieren'
    );

    $flach = collect($out['varianten'])->firstWhere('behaelter', 'GN 1/1 65mm');

    // Wirkfläche 9,0/65 vs 14,0/100 → 0,9890; Tiefenfaktor 65/100 → zusammen 0,6429 × 12 kg.
    expect($flach['kg_je_behaelter'])->toBe(7.714)
        ->and($flach['konfidenz'])->toBe('mittel');
});

it('tiefer_fuellbar nutzt die volle Tiefe — bis der Handhabungs-Deckel greift', function () {
    $out = $this->r->varianten(
        60.0,
        ($this->basis)(['referenz_menge_kg' => 8.0, 'skalierung' => 'tiefer_fuellbar']),
        [$this->gn11_200],
        'regenerieren'
    );

    $tief = collect($out['varianten'])->firstWhere('behaelter', 'GN 1/1 200mm');

    // Rechnerisch ~25 kg Suppe in einem GN 1/1-200 — korrekt, aber niemand trägt das.
    expect($tief['auf_deckel_gekappt'])->toBeTrue()
        ->and($tief['kg_je_behaelter'])->toBe(15.0)
        ->and($tief['anzahl'])->toBe(4);
});

it('gepflegte Schichthöhe schlägt die Faustregel und hält die Konfidenz', function () {
    $out = $this->r->varianten(
        10.0,
        ($this->basis)(['container' => $this->gn11_100, 'referenz_menge_kg' => 12.0, 'max_schichthoehe_mm' => 50.0]),
        [$this->gn11_65],
        'regenerieren'
    );

    $flach = collect($out['varianten'])->firstWhere('behaelter', 'GN 1/1 65mm');

    // Die Ware steht nur 50 mm hoch — beide Behälter fassen sie, es zählt nur die Fläche.
    expect($flach['kg_je_behaelter'])->toBe(11.868)
        ->and($flach['konfidenz'])->toBe('hoch');
});

it('ein Behälter ohne Freigabe für den Zweck liefert einen Grund, keine Zahl', function () {
    $out = $this->r->varianten(
        40.0,
        ['container' => $this->eimer, 'referenz_menge_kg' => 9.0, 'dichteklasse' => null,
            'skalierung' => 'tiefer_fuellbar', 'max_schichthoehe_mm' => null, 'konfidenz_rang3' => false],
        [],
        'regenerieren'
    );

    expect($out['berechenbar'])->toBeFalse()
        ->and($out['grund'])->toContain('regenerieren');
});

it('nicht freigegebene Kandidaten tauchen gar nicht erst als Alternative auf', function () {
    $out = $this->r->varianten(
        40.0,
        ($this->basis)(['referenz_menge_kg' => 8.0]),
        [$this->eimer],
        'regenerieren'
    );

    expect(collect($out['varianten'])->pluck('behaelter'))->not->toContain('Eimer 10 l');
});

it('Lagenware ohne Stückzahl rechnet nicht, sondern sagt warum', function () {
    $out = $this->r->varianten(
        10.0,
        ($this->basis)(['referenz_menge_kg' => 8.0, 'skalierung' => 'lagenware']),
        [],
        'regenerieren'
    );

    expect($out['berechenbar'])->toBeFalse()
        ->and($out['grund'])->toContain('Stückzahl');
});

it('derselbe Behälter zum Abfüllen und Regenerieren zählt EINMAL', function () {
    $ab = $this->r->varianten(10.0, ($this->basis)(['referenz_menge_kg' => 8.0]), [], 'abfuellen');
    $re = $this->r->varianten(10.0, ($this->basis)(['referenz_menge_kg' => 8.0]), [], 'regenerieren');

    $out = $this->r->zusammenlegen($ab, $re);

    expect($out['durchgaengig'])->toBeTrue()
        ->and($out['anzahl'])->toBe(2)
        ->and($out['hinweis'])->toContain('kein Umfüllen');
});

it('verschiedene Behälter benennen den Umfüll-Schritt', function () {
    $ab = $this->r->varianten(
        40.0,
        ['container' => $this->eimer, 'referenz_menge_kg' => 9.0, 'dichteklasse' => null,
            'skalierung' => 'tiefer_fuellbar', 'max_schichthoehe_mm' => null, 'konfidenz_rang3' => false],
        [],
        'abfuellen'
    );
    $re = $this->r->varianten(40.0, ($this->basis)(['referenz_menge_kg' => 8.0]), [], 'regenerieren');

    $out = $this->r->zusammenlegen($ab, $re);

    expect($out['durchgaengig'])->toBeFalse()
        ->and($out['hinweis'])->toContain('Umfüllen am Einsatztag');
});

it('Lagenware rechnet über Stück — nicht über Masse', function () {
    // 3 kg Papadam füllen kein GN zu 3 kg, sondern zu einer Lage. 240 Stück à 40 je Blech = 6.
    $out = $this->r->varianten(3.0, ($this->basis)([
        'skalierung' => 'lagenware', 'stueck_je_behaelter' => 40, 'stueck_gesamt' => 240,
    ]), [$this->gn11_100], 'regenerieren');

    expect($out['berechenbar'])->toBeTrue()
        ->and($out['varianten'])->toHaveCount(1)          // keine Alternativen: Legefläche wäre geraten
        ->and($out['varianten'][0]['anzahl'])->toBe(6)
        ->and($out['varianten'][0]['kg_je_behaelter'])->toBeNull();
});

it('Lagenware ohne Stückertrag rechnet nicht, sondern sagt warum', function () {
    $out = $this->r->varianten(3.0, ($this->basis)([
        'skalierung' => 'lagenware', 'stueck_je_behaelter' => 40, 'stueck_gesamt' => null,
    ]), [], 'regenerieren');

    expect($out['berechenbar'])->toBeFalse()->and($out['grund'])->toContain('yield_pieces');
});

it('rechnet das Volumen NICHT aus den Kantenlängen — auch wenn Maße da sind', function () {
    // Echtdaten-Befund (demo, 2026-09-04): fuer 20-mm-Formate veroeffentlicht der Handel kein
    // Litermass (Einlegeschalen). Die Kantenrechnung sprang ein und lieferte 2,928 l statt ~2,1 l
    // — 38 % zu hoch, still, und als Zahl praesentiert. GN-Behaelter sind konisch.
    $ohneVolumen = ($this->gn)(99, 'GN 1/1 20mm', 530, 325, 20, 0.0);
    $ohneVolumen->volumen_l = null;

    expect(BehaelterRechner::nutzvolumenL($ohneVolumen))->toBeNull();

    $out = $this->r->varianten(5.0, ($this->basis)([
        'container' => $ohneVolumen, 'dichteklasse' => 'dicht',
    ]), [], 'regenerieren');

    expect($out['berechenbar'])->toBeFalse()
        ->and($out['grund'])->toContain('Maße');
});

it('das Verhältnis zweier Formate bleibt nutzbar, auch ohne eigenes Nennvolumen', function () {
    // Die Masse taugen weiterhin fuer die WIRKFLAECHE — dort kuerzt sich der Konizitaets-Fehler
    // weitgehend heraus. Nur das ABSOLUTE Volumen laesst sich nicht daraus ableiten.
    $out = $this->r->varianten(10.0, ($this->basis)(['referenz_menge_kg' => 8.0]), [$this->gn12_65], 'regenerieren');

    expect(collect($out['varianten'])->firstWhere('behaelter', 'GN 1/2 65mm')['kg_je_behaelter'])->toBe(3.556);
});

it('die Menge wählt die Größe — 9 kg nehmen den 10-l-Eimer, 4 kg den 5-l', function () {
    // Der Fall aus der Kueche: »wenn ich 9 Kilo koche, brauche ich den 10-Liter-Eimer oder zwei
    // 5-Liter. Bei 4 schlaegt er nur den 5-Liter vor.« Vorher stand immer der am Rezept
    // EINGESTELLTE Behaelter vorn, egal wie schlecht er passte.
    $eimer = fn (int $id, string $name, float $liter) => (object) [
        'id' => $id, 'name' => $name, 'familie' => 'Eimer',
        'laenge_mm' => null, 'breite_mm' => null, 'tiefe_mm' => null, 'volumen_l' => $liter,
        'nutzfaktor' => 0.9, 'max_fuellgewicht_kg' => $liter, 'kapazitaet_kg' => null, 'eignung' => null,
    ];
    $e5 = $eimer(1, 'Eimer 5 l', 5);      // nutzbar 4,5 kg
    $e10 = $eimer(2, 'Eimer 10 l', 10);   // nutzbar 9,0 kg

    $basis = fn ($ref) => ['container' => $ref, 'referenz_menge_kg' => null, 'dichteklasse' => 'fluessig',
        'skalierung' => 'tiefer_fuellbar', 'max_schichthoehe_mm' => null, 'konfidenz_rang3' => false];

    // 9 kg: ein 10-l (Rest 0) schlaegt zwei 5-l (Rest 0) — wenigste Behaelter.
    $neun = $this->r->varianten(9.0, $basis($e5), [$e10], 'abfuellen');
    expect($neun['varianten'][0]['behaelter'])->toBe('Eimer 10 l')
        ->and($neun['varianten'][0]['anzahl'])->toBe(1)
        ->and($neun['varianten'][1]['behaelter'])->toBe('Eimer 5 l')
        ->and($neun['varianten'][1]['anzahl'])->toBe(2);

    // 4 kg: beide brauchen EINEN — dann gewinnt der mit weniger Luft.
    $vier = $this->r->varianten(4.0, $basis($e10), [$e5], 'abfuellen');
    expect($vier['varianten'][0]['behaelter'])->toBe('Eimer 5 l')
        ->and($vier['varianten'][0]['anzahl'])->toBe(1)
        ->and($vier['varianten'][0]['rest_im_letzten_kg'])->toBe(0.5)
        // Der eingestellte Behaelter bleibt erkennbar — er ist die Wissensquelle, nicht der Vorschlag.
        ->and(collect($vier['varianten'])->firstWhere('ist_referenz', true)['behaelter'])->toBe('Eimer 10 l');
});

it('die Menge waehlt die Groesse, nie die Bauform — tiefer als die Referenz fuehrt nie', function () {
    // Gegenprobe zum Fall darueber: Suppe (tiefer_fuellbar) kann im GN 1/1-100 rechnerisch in EINEN
    // Behaelter, statt in zwei flache GN 1/1-65. Genau das darf der Rechner NICHT vorschlagen —
    // »nie automatisch flach↔tief umschalten«, flach regeneriert gleichmaessiger.
    $out = $this->r->varianten(
        10.0,
        ($this->basis)(['referenz_menge_kg' => 7.5, 'skalierung' => 'tiefer_fuellbar']),
        [$this->gn11_100],
        'regenerieren'
    );

    expect($out['varianten'][0]['behaelter'])->toBe('GN 1/1 65mm')
        ->and($out['varianten'][0]['anzahl'])->toBe(2)
        // sichtbar bleibt das tiefe GN trotzdem — waehlen darf es die Kueche.
        ->and(collect($out['varianten'])->pluck('behaelter'))->toContain('GN 1/1 100mm');

    // Nach unten darf er fuehren: bei 3 kg ist das flachere, kleinere GN 1/2-65 die bessere Passung.
    $klein = $this->r->varianten(3.0, ($this->basis)(['referenz_menge_kg' => 7.5]), [$this->gn12_65], 'regenerieren');
    expect($klein['varianten'][0]['behaelter'])->toBe('GN 1/2 65mm')
        ->and($klein['varianten'][0]['anzahl'])->toBe(1);
});

it('bietet einen Behaelter ohne Nennvolumen gar nicht erst an', function () {
    // Auf demo gemessen: GN 1/2-20 hat kein Litermaß (der Handel veroeffentlicht dort keins).
    // Der alte Kanten-Rueckfall rechnete daraus 117 kg — real fasst es unter 1 kg — und stellte
    // die Zeile direkt hinter den Vorschlag. Zweimal falsch: der Faktor (1 cm² × 1 mm = 0,0001 l,
    // nicht 0,01 l) UND das Prinzip (GN ist konisch, Kanten ueberschaetzen um ~21 %).
    $ohneVolumen = (object) [
        'id' => 7, 'name' => 'GN 1/2 20mm', 'familie' => 'GN',
        'laenge_mm' => 325, 'breite_mm' => 265, 'tiefe_mm' => 20, 'volumen_l' => null,
        'nutzfaktor' => 0.85, 'max_fuellgewicht_kg' => null, 'kapazitaet_kg' => null, 'eignung' => null,
    ];

    $out = $this->r->varianten(
        9.0,
        ($this->basis)(['referenz_menge_kg' => 6.0, 'skalierung' => 'tiefer_fuellbar']),
        [$ohneVolumen, $this->gn11_100],
        'regenerieren'
    );

    $namen = collect($out['varianten'])->pluck('behaelter');
    expect($namen)->not->toContain('GN 1/2 20mm')     // nicht skalierbar → nicht anbieten
        ->and($namen)->toContain('GN 1/1 65mm')       // die Referenz bleibt
        ->and($namen)->toContain('GN 1/1 100mm');     // bemessbare Alternativen bleiben
});

it('rechnet eine KI-hergeleitete Referenzmenge mit niedrigerer Konfidenz als eine gewogene', function () {
    // Beide Male dieselbe Zahl — aber nicht dieselbe Aussage. Ohne diese Unterscheidung waere
    // eine hergeleitete Menge von einer in der Kueche gewogenen nicht mehr zu trennen, und die
    // Bemessung behauptete Genauigkeit, die sie nicht hat.
    $basis = fn (?string $quelle) => ($this->basis)([
        'referenz_menge_kg' => 6.0, 'skalierung' => 'hoehe_gebunden', 'referenz_quelle' => $quelle,
    ]);

    $gewogen = $this->r->varianten(12.0, $basis('manual'), [], 'regenerieren');
    $hergeleitet = $this->r->varianten(12.0, $basis('ki'), [], 'regenerieren');

    expect($gewogen['varianten'][0]['konfidenz'])->toBe('hoch')
        ->and($hergeleitet['varianten'][0]['konfidenz'])->toBe('mittel')
        // Die MENGE ist identisch — nur die Aussage ueber ihre Belastbarkeit unterscheidet sich.
        ->and($hergeleitet['varianten'][0]['anzahl'])->toBe($gewogen['varianten'][0]['anzahl']);
});
