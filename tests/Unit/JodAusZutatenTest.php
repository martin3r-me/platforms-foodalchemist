<?php

use Platform\FoodAlchemist\Support\JodAusZutaten;

/**
 * Jod aus der Zutatenliste: echte Textstellen aus den Lieferanten-Zutatenlisten der WaWi-Kopie (Stand 15.10.2026), gekürzt.
 */
dataset('jodiert', [
    'jodiertes Speisesalz' => ['Weizenmehl, Wasser, Hefe, jodiertes Speisesalz, Gerstenmalz'],
    'Jodsalz' => ['Schweinefleisch, Jodsalz, Gewürze, Dextrose'],
    'Speisejodsalz' => ['Rindfleisch, Speisejodsalz, Pfeffer'],
    'Speisesalz und Kaliumjodat' => ['Kartoffeln, Speisesalz und Kaliumjodat, Muskat'],
    'Speisesalz und Kaliumjodid' => ['Trinkwasser, Speisesalz und Kaliumjodid, Zucker'],
    'Kaliumiodat' => ['jod. Speisesalz [ Speisesalz, Kaliumiodat], Gewürzextrakt'],
    'Kaliumiodid' => ['Salz (Speisesalz, Kaliumiodid), Gewürze'],
    'Natriumjodat' => ['Speisesalz, Natriumjodat, Trennmittel'],
    'Kaliumjodad (Tippfehler)' => ['(Salz, Kaliumjodad), Mineralstoffe (Eisen, Zink)'],
    'Kaliumjodaat' => ['Zout, Kaliumjodaat'],
    'Kalimjodat (Tippfehler)' => ['Salz, Kalimjodat]'],
    'Kaliumiodad' => ['Speisesalz (Kaliumiodad), Pfeffer'],
    'jodiertes Nitritpökelsalz' => ['Schweinefleisch, jodiertes Nitritpökelsalz (Kochsalz, Konservierungsstoff E250)'],
    'jod. Nitritpökelsalz' => ['Schweinefleisch, Trinkwasser, jod. Nitritpökelsalz (Kochsalz, Konservierungsstoff E250)'],
    'jod.Nitritpökelsalz ohne Leerzeichen' => ['Kasseler (Schweinefleisch, Trinkwasser, jod.Nitritpökelsalz (Kochsalz)'],
    'jod. Kochsalz' => ['Putenfleisch, Speck, Trinkwasser, jod. Kochsalz, Gewürze (enthält SENF)'],
    'Salz jod.' => ['Hefe, Sonnenblumenöl, Salz jod., Malzextrakt (GERSTE)'],
    'Speisesalz jodiert' => ['Spinat 0,32% Gewürze, Speisesalz jodiert, Zucker'],
    'Salz jodiert' => ['Wasser, Salz jodiert (Salz, Kaliumjodid), Zwiebel'],
    'jodiertes Kochsalz' => ['Hackfleisch, jodiertes Kochsalz [Speisesalz, Kaliumjodat]'],
    'jodiertes Meersalz' => ['Tomaten, jodiertes Meersalz, Basilikum'],
    'iodiertes Speisesalz' => ['Kichererbsen, iodiertes Speisesalz, Kreuzkümmel'],
    'Speisesalz iodiert' => ['Speisesalz iodiert [Siedesalz, Kaliumiodat]'],
    'jodietes (Tippfehler)' => ['Rapsöl, jodietes Speisesalz, Senfsaat'],
    'jodiertets (Tippfehler)' => ['Kalbfleisch, jodiertets Kochsalz'],
    'jodiertesSpeisesalz geklebt' => ['Palmfett, Rapsöl, jodiertesSpeisesalz, (Speisesalz, Kaliumjodid)'],
    'Trenner #' => ['Wasser#jodiertes#speisesalz#Hefe'],
    'mit Jodzusatz' => ['Meersalz mit Jodzusatz'],
    'Jod beim Salz (mg/kg)' => ['Natriumchlorid (99,9%), Jod (15-25mg/kg), Trennmittel (E535)'],
    'Jod beim Salz (KIO3)' => ['Natriumchlorid, Trennmittel: E551, E535, Jod (zugefügt als KIO3)'],
    'niederländisch' => ['gejodeerd zout, suiker'],
    'nach „<1%“ (strip_tags-Falle)' => ['Vanillezucker <1% (Zucker, Vanillin), jodiertes Speisesalz (Salz, Kaliumjodat).'],
    'jodiert UND unjodiert gemischt' => ['Rapsöl, jodiertesSpeisesalz, (Speisesalz, Kaliumjodid), unjodiertes Speisesalz, Aroma.'],
]);

dataset('nicht_jodiert', [
    'unjodiertes Speisesalz' => ['Schweinefleisch, unjodiertes Speisesalz, Gewürze'],
    'Speisesalz unjodiert' => ['Weizenmehl, Wasser, Hefe, Speisesalz unjodiert, Dextrose'],
    'Salz (unjodiert)' => ['Weizenmehl*, Hefe*, Salz (unjodiert)'],
    'unjodiertes Ursalz' => ['Zutaten: unjodiertes Ursalz.'],
    'Meersalz unjodiert' => ['Schweinefleisch, Meersalz unjodiert, Gewürze.'],
    'geklebt Speisesalzunjodiert' => ['Pfeffer, Speisesalzunjodiert'],
    'geklebt Ursalzunjodiert' => ['12% Ursalzunjodiert, Kräuter'],
    'jodfrei' => ['Steinsalz, jodfrei'],
    'ohne Jod' => ['Meersalz ohne Jod'],
    'nicht jodiert' => ['Speisesalz (nicht jodiert), Pfeffer'],
    'Pökelsalz unjodiert' => ['Nitritpökelsalz (Salz unjodiert, Konservierungsmittel: Natriumnitrit)'],
]);

dataset('keine_aussage', [
    'Mineralstoff-Aufzählung' => ['Mineralstoffe (Kalium, Calcium, Magnesium, Zink, Mangan, Selen, Jod), Maltodextrin, Süßungsmittel: Sucralose'],
    'Mineralstoffe mit Molybdän' => ['(Magnesium, Eisen, Kupfer, Mangan, Selen, Chrom, Molybdän, Jod), Emulgator: Lecithine'],
    'Algen-Warnhinweis' => ['Algen enthalten einen erhöhten Anteil an Jod. Übermäßiger Verzehr kann daher zu Störungen der Schilddrüse führen.'],
    'Diglycerioden' => ['Emulgatoren (Mono- und Diglycerioden aus Speisefettsäuren, Polyglycerolester)'],
    'biodynamisch' => ['Zitronensaft**. **aus biodynamischer Erzeugung'],
    'citriodora' => ['Zitronenverbenenblätter ganz (Verbenaecitriodoratae Fol. tot.).'],
    'Salz ohne Angabe' => ['Weizenmehl, Wasser, Salz, Hefe'],
    'Speisesalz ohne Angabe' => ['Schweinefleisch, Speisesalz, Gewürze'],
    'Pökelsalz ohne Angabe' => ['Nitritpökelsalz (Kochsalz, Konservierungsstoff E250)'],
    'Periode' => ['Periodenweise geliefert: Äpfel'],
    'Jodtinktur-artig ohne Salzbezug' => ['Kelp (Jodgehalt schwankend)'],
    'leer' => [''],
    'Leerzeichen' => ['   '],
]);

it('erkennt jodiertes Salz', function (string $text) {
    expect(JodAusZutaten::wert($text))->toBe(JodAusZutaten::JA);
})->with('jodiert');

it('erkennt ausdrücklich unjodiertes Salz', function (string $text) {
    expect(JodAusZutaten::wert($text))->toBe(JodAusZutaten::NEIN);
})->with('nicht_jodiert');

it('trifft keine Aussage bei Mineralstoffen, Fehltreffern und Salz ohne Angabe', function (string $text) {
    expect(JodAusZutaten::wert($text))->toBeNull();
})->with('keine_aussage');

it('ohne Zutatenliste: keine Aussage', function () {
    expect(JodAusZutaten::wert(null))->toBeNull();
});
