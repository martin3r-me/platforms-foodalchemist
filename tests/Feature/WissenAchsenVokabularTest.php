<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Knowledge\WissensAchsenVokabular;
use Platform\FoodAlchemist\Services\Knowledge\WissensGeltung;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 52 Runde B — Achsenwerte gegen das VORHANDENE Vokabular pruefen.
 *
 * Der Anlass ist ein eigener Fehler: beim ersten Einordnen habe ich dreimal Werte
 * geschrieben, die es bei FoodAlchemist nicht gibt (`protein` statt `komponente`,
 * `obst_zitrus` statt der GP-Warengruppe, `getraenk` als Rolle). Solche Werte fallen nicht
 * auf — sie treffen einfach nie. Bei 6 Dossiers billig, bei 1.073 nicht.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    /*
     * ⚠ Die Stammdaten-Tabellen sind im Test-Harness LEER. Ohne dieses Saeen laeuft die
     * Regel „leere Quelle schraenkt nicht ein" — und die Pruefung fuer `gang` und
     * `warengruppe` waere hier gar nicht aktiv. Der Test waere gruen und wuerde nichts
     * beweisen, waehrend sie auf demo (Tabellen gefuellt) sehr wohl greift. Genau die
     * Sorte Fixture-Luege, die schon zweimal zugeschlagen hat.
     */
    $uuid = fn () => (string) \Symfony\Component\Uid\UuidV7::generate();
    foreach ([['HG', 'Hauptgang', 0], ['VOR', 'Vorspeise', 0], ['DES', 'Dessert', 0], ['ALT', 'Stillgelegt', 1]] as [$c, $l, $inaktiv]) {
        DB::table('foodalchemist_dish_main_groups')->insert([
            'uuid' => $uuid(), 'code' => $c, 'label' => $l, 'is_inactive' => $inaktiv,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    foreach ([['02', '02 Obst'], ['15', '15 Getraenke']] as [$c, $l]) {
        DB::table('foodalchemist_lookup_commodity_groups')->insert([
            'uuid' => $uuid(), 'code' => $c, 'name' => $l,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
});

it('★ prueft die DB-gestuetzten Achsen wirklich — mit gefuellter Quelle', function () {
    expect(fn () => WissensGeltung::normalisieren(['gang' => ['hauptgang']]))
        ->toThrow(RuntimeException::class, 'Unbekannter Wert');            // der Klartext ist kein Code
    expect(WissensGeltung::normalisieren(['gang' => ['hg']]))->toBe(['gang' => ['hg']]);
});

it('★ eine STILLGELEGTE Hauptgruppe ist nicht mehr taggbar', function () {
    expect(WissensAchsenVokabular::gueltig('gang', 'alt'))->toBeFalse()
        ->and(WissensAchsenVokabular::gueltig('gang', 'hg'))->toBeTrue();
});

it('weist einen erfundenen Achsenwert ab, statt ihn still anzunehmen', function () {
    expect(fn () => WissensGeltung::normalisieren(['komponentenrolle' => ['protein']]))
        ->toThrow(RuntimeException::class, 'Unbekannter Wert');
});

it('nennt in der Fehlermeldung die erlaubten Werte — sonst raet der naechste wieder', function () {
    try {
        WissensGeltung::normalisieren(['komponentenrolle' => ['protein']]);
        $this->fail('haette werfen muessen');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('komponente')->toContain('beilage');
    }
});

it('nimmt die gepflegten Rollen an', function (string $rolle) {
    expect(WissensGeltung::normalisieren(['komponentenrolle' => [$rolle]]))
        ->toBe(['komponentenrolle' => [$rolle]]);
})->with(['aroma_treiber', 'komponente', 'beilage', 'garnitur']);

it('★ schraenkt NICHT ein, wenn die Quelle leer ist — eine frische Umgebung darf nicht blockieren', function () {
    DB::table('foodalchemist_dish_main_groups')->delete();
    expect(WissensAchsenVokabular::werte('gang'))->toBeNull()
        ->and(WissensGeltung::normalisieren(['gang' => ['was_auch_immer']]))->toBe(['gang' => ['was_auch_immer']]);
});

it('laesst eine bewusst freie Achse frei', function () {
    // `portionskontext` hat (Entscheid Dominique 2026-09-11) kein Vokabular — ein
    // vorsorgliches waere geraten, kein Vokabular.
    expect(WissensAchsenVokabular::werte('portionskontext'))->toBeNull()
        ->and(WissensGeltung::normalisieren(['portionskontext' => ['menue']]))->toBe(['portionskontext' => ['menue']]);
});

it('kennt die Saison als MONATE, nicht als vier Jahreszeiten', function () {
    // Der Saisonkalender ist nach Monatspaaren geschnitten (Jan/Feb, Maerz/Apr, …).
    // Vier Jahreszeiten waeren groeber als die Daten.
    expect(WissensAchsenVokabular::werteListe('saison'))->toContain('01')->toContain('12')
        ->and(WissensAchsenVokabular::gueltig('saison', 'sommer'))->toBeFalse();
});

it('zieht das Gang-Vokabular aus den Speisen-Hauptgruppen', function () {
    $codes = WissensAchsenVokabular::werteListe('gang') ?? [];
    $aktiv = DB::table('foodalchemist_dish_main_groups')->where('is_inactive', 0)->pluck('code')
        ->map(fn ($c) => mb_strtolower($c))->all();
    expect($codes)->toEqualCanonicalizing($aktiv);
});

it('zieht das Warengruppen-Vokabular aus der Taxonomie', function () {
    expect(WissensAchsenVokabular::werteListe('warengruppe'))->toEqualCanonicalizing(['02', '15']);
});

/**
 * Die Migration holt den Bestand nach — sonst wuerden die sechs Mengen-Dossiers beim
 * naechsten Speichern an der neuen Pruefung scheitern, und bis dahin still nie treffen.
 */
it('★ Migration zieht meine erfundenen Werte auf das echte Vokabular', function () {
    $id = DB::table('foodalchemist_knowledge_documents')->insertGetId([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
        'slug' => 'alt-werte', 'title' => 'Alt', 'category' => 'cross_cutting',
        'content_md' => 'x', 'version' => 1, 'content_hash' => str_repeat('e', 64),
        'char_count' => 1, 'active' => 1, 'art' => 'datenwerk',
        'geltung' => json_encode(['gang' => ['hauptgang'], 'komponentenrolle' => ['protein']]),
        'datenwerte' => json_encode([
            ['kennzahl' => 'a', 'min' => 1, 'max' => 1, 'einheit' => 'g', 'bezug' => 'roh',
             'quelle' => 'T', 'geltung' => ['warengruppe' => ['obst_zitrus']]],
            ['kennzahl' => 'b', 'min' => 1, 'max' => 1, 'einheit' => 'ml', 'bezug' => 'roh',
             'quelle' => 'T', 'geltung' => ['komponentenrolle' => ['getraenk']]],
            ['kennzahl' => 'c', 'min' => 1, 'max' => 1, 'einheit' => 'ml', 'bezug' => 'gegart',
             'quelle' => 'T', 'geltung' => ['komponentenrolle' => ['suppe']]],
            ['kennzahl' => 'd', 'min' => 0.1, 'max' => 0.1, 'einheit' => 'faktor',
             'bezug' => 'relativ_a_la_carte', 'quelle' => 'T', 'geltung' => ['gang' => ['petit_four']]],
        ]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    (require __DIR__.'/../../database/migrations/2026_09_11_100000_achsenwerte_auf_vokabular.php')->up();

    $z = DB::table('foodalchemist_knowledge_documents')->where('id', $id)->first();
    $g = json_decode($z->geltung, true);
    $w = json_decode($z->datenwerte, true);

    expect($g['gang'])->toBe(['hg'])                              // Speisen-Hauptgruppen-Code
        ->and($g['komponentenrolle'])->toBe(['komponente'])       // euer Rollen-Vokabular
        ->and($w[0]['geltung']['warengruppe'])->toBe(['02'])      // GP-Taxonomie: Obst
        ->and($w[1]['geltung'])->toBe(['warengruppe' => ['15']])  // Getraenk ist keine ROLLE
        ->and($w[2]['geltung'])->toBe(['gang' => ['sup']])        // Suppe auch nicht
        // ⚠ Petit Four ist keine Speisen-Hauptgruppe — nicht hineingezwaengt, sondern weg.
        ->and($w[3]['geltung'])->toBe([]);
});

it('★ nach der Migration sind ALLE Werte gueltiges Vokabular — sonst scheitert der naechste Schreibzugriff', function () {
    $id = DB::table('foodalchemist_knowledge_documents')->insertGetId([
        'uuid' => (string) \Symfony\Component\Uid\UuidV7::generate(),
        'slug' => 'alt-werte-2', 'title' => 'Alt2', 'category' => 'cross_cutting',
        'content_md' => 'x', 'version' => 1, 'content_hash' => str_repeat('f', 64),
        'char_count' => 1, 'active' => 1,
        'geltung' => json_encode(['gang' => ['vorspeise', 'dessert'], 'komponentenrolle' => ['protein']]),
        'datenwerte' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    (require __DIR__.'/../../database/migrations/2026_09_11_100000_achsenwerte_auf_vokabular.php')->up();

    $g = json_decode(DB::table('foodalchemist_knowledge_documents')->where('id', $id)->value('geltung'), true);
    // Der eigentliche Beweis: die migrierte Geltung laeuft durch die neue Pruefung.
    expect(fn () => WissensGeltung::normalisieren($g))->not->toThrow(RuntimeException::class);
    expect($g['gang'])->toBe(['vor', 'des']);
});
