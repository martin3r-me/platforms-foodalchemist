<?php

use Platform\FoodAlchemist\Services\Knowledge\WissensGeltung;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 52 — `ausgabeform` statt `format`, abgeleitet aus Sektor x Serviceform.
 *
 * Zwei Gruende fuer die Umbenennung: „Format" ist in FoodAlchemist bereits eine
 * Konzeptzusammenstellung (ein Produkt mit Slots und Kalkulation), und die Achse wurde
 * nie uebergeben — der Mengen-Multiplikator feuerte also nie.
 *
 * Warum kombiniert abgeleitet und nicht aus der Serviceform allein: Bankett-Buffet und
 * Kantinen-Buffet tragen verschiedene Faktoren (0,75-0,85 vs. 0,85-0,95) bei identischer
 * Serviceform. Erst der Sektor entscheidet.
 */
beforeEach(fn () => $this->seedTeamHierarchy());

it('leitet die Ausgabeform aus Sektor x Serviceform ab', function (string $sektor, string $service, string $erwartet) {
    $p = WissensGeltung::mitAbleitung(['sektor' => $sektor, 'serviceform' => $service]);
    expect($p['ausgabeform'] ?? null)->toBe($erwartet);
})->with([
    ['restaurant', 'tellerservice', 'a_la_carte'],
    ['catering', 'tellerservice', 'bankett_tellergericht'],
    ['catering', 'buffet', 'bankett_buffet'],
    ['betriebsgastronomie', 'buffet', 'volumen_catering'],        // Sektor gewinnt, Serviceform egal
    ['betriebsgastronomie', 'tellerservice', 'volumen_catering'],
]);

it('★ raet KEINE Kombination, die nicht in der Tabelle steht', function (string $sektor, string $service) {
    // Ein falscher Mengen-Faktor ist teurer als ein fehlender: lieber Luecke.
    $p = WissensGeltung::mitAbleitung(['sektor' => $sektor, 'serviceform' => $service]);
    expect($p)->not->toHaveKey('ausgabeform');
})->with([
    ['catering', 'flying'],
    ['care', 'boxed'],
    ['schule_kita', 'buffet'],
    ['restaurant', 'stehempfang'],
]);

it('laesst eine ausdruecklich gesetzte Ausgabeform unangetastet — der Mensch weiss mehr als die Regel', function () {
    $p = WissensGeltung::mitAbleitung([
        'sektor' => 'catering', 'serviceform' => 'buffet', 'ausgabeform' => 'foodtruck',
    ]);
    expect($p['ausgabeform'])->toBe('foodtruck');
});

it('leitet ohne Sektor nichts ab', function () {
    expect(WissensGeltung::mitAbleitung(['serviceform' => 'buffet']))->not->toHaveKey('ausgabeform');
});

it('greift auch beim Geltungs-Abgleich, nicht nur beim Aufbau', function () {
    // passt() leitet selbst ab — sonst haengt die Wirkung daran, wer die Parameter baut.
    expect(WissensGeltung::passt(['ausgabeform' => ['bankett_buffet']],
        ['sektor' => 'catering', 'serviceform' => 'buffet']))->toBeTrue()
        ->and(WissensGeltung::passt(['ausgabeform' => ['bankett_buffet']],
            ['sektor' => 'restaurant', 'serviceform' => 'tellerservice']))->toBeFalse();
});

it('kennt die Achse `format` nicht mehr — eine alte Geltung wird abgewiesen statt still angenommen', function () {
    expect(fn () => WissensGeltung::normalisieren(['format' => ['bankett_buffet']]))
        ->toThrow(RuntimeException::class, 'Unbekannte Achse');
    expect(array_keys(WissensGeltung::ACHSEN))->toContain('ausgabeform');
});

/**
 * ★ Die Migration ist kein Beiwerk, sondern der Grund, warum die Umbenennung sicher ist.
 *
 * `WissensGeltung::lesen()` validiert nicht, es decodiert nur. Eine zurueckgebliebene
 * `format`-Geltung wuerde gegen einen Parameter geprueft, den niemand mehr schickt — sie
 * traefe nie mehr, OHNE Fehlermeldung. Genau die stille Sorte Ausfall, die Spec 52 abbaut.
 */
it('★ Migration zieht Dokument-Geltung UND Datenwert-Geltung mit', function () {
    $id = \Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_documents')->insertGetId([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
        'slug' => 'alt-format', 'title' => 'Alt', 'category' => 'cross_cutting',
        'content_md' => 'x', 'version' => 1, 'content_hash' => str_repeat('c', 64),
        'char_count' => 1, 'active' => 1, 'art' => 'datenwerk',
        // bewusst am Validator vorbei eingefuegt — so sieht der Bestand VOR der Migration aus
        'geltung' => json_encode(['format' => ['bankett_buffet'], 'gang' => ['hauptgang']]),
        'datenwerte' => json_encode([[
            'kennzahl' => 'mengen_faktor.bankett_buffet', 'min' => 0.75, 'max' => 0.85,
            'einheit' => 'faktor', 'bezug' => 'relativ_a_la_carte', 'quelle' => 'Test',
            'geltung' => ['format' => ['bankett_buffet']],
        ]]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $migration = require __DIR__.'/../../database/migrations/2026_09_11_090000_achse_format_zu_ausgabeform.php';
    $migration->up();

    $z = \Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_documents')->where('id', $id)->first();
    $geltung = json_decode($z->geltung, true);
    $werte = json_decode($z->datenwerte, true);

    expect($geltung)->toHaveKey('ausgabeform')->not->toHaveKey('format')
        ->and($geltung['ausgabeform'])->toBe(['bankett_buffet'])
        ->and($geltung['gang'])->toBe(['hauptgang'])                    // andere Achsen unangetastet
        ->and($werte[0]['geltung'])->toHaveKey('ausgabeform')->not->toHaveKey('format')
        ->and($werte[0]['min'])->toBe(0.75);                            // Werte unveraendert

    // Und danach trifft die Geltung wieder — das ist der eigentliche Zweck.
    expect(WissensGeltung::passt($geltung, ['gang' => 'hauptgang', 'sektor' => 'catering', 'serviceform' => 'buffet']))
        ->toBeTrue();
});

it('Migration laesst Dossiers ohne die alte Achse in Ruhe', function () {
    $id = \Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_documents')->insertGetId([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
        'slug' => 'ohne-format', 'title' => 'Ohne', 'category' => 'cross_cutting',
        'content_md' => 'x', 'version' => 1, 'content_hash' => str_repeat('d', 64),
        'char_count' => 1, 'active' => 1,
        'geltung' => json_encode(['gang' => ['dessert']]), 'datenwerte' => null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $vorher = \Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_documents')->where('id', $id)->value('geltung');

    (require __DIR__.'/../../database/migrations/2026_09_11_090000_achse_format_zu_ausgabeform.php')->up();

    expect(\Illuminate\Support\Facades\DB::table('foodalchemist_knowledge_documents')->where('id', $id)->value('geltung'))
        ->toBe($vorher);
});
