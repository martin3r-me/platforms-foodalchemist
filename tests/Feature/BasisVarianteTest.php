<?php

use Platform\FoodAlchemist\Enums\MatchBand;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Basisvariante (Kurator 27.10.): ohne genannte Variante nie automatisch Bio, Marke, Zuschnitt, Verarbeitung oder Fettstufe. Es gilt die
 * Basisvariante derselben Hauptzutat (frisch vor TK/trocken/konserviert); gibt es keine eindeutige, wird der Treffer FuzzyLow (Review).
 * Nennt der Text die Variante, darf sie automatisch gewinnen.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));
    config(['foodalchemist.semantic_search.enabled' => false]);
    $this->nr = 0;
    // Warengruppe nach §3 (wichtig für die WG-gebundenen Regeln: Frischware 01–03, gemahlen-Zukauf 10/12.3/15.8)
    $wg = ['rohrohrzucker' => '09', 'rinderbruehe' => '13', 'schalotte' => '01', 'basilikum' => '03', 'sahne' => '06', 'zwiebel' => '01', 'joghurt' => '06'];
    $this->gp = fn (string $name, string $slug, ?string $zustand, ?string $zusatz = null, ?string $bio = null) => FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'name' => $name, 'gp_key' => 'bv-' . (++$this->nr) . '||', 'status' => 'approved', 'requires_la' => true,
        'commodity_group_code' => $wg[$slug] ?? '01', 'condition' => $zustand, 'main_ingredient_slug' => $slug, 'zusatz' => $zusatz, 'bio' => $bio]);
    $this->g = [
        'zuckerBio' => ($this->gp)('Rohrohrzucker: trocken / (Bio)', 'rohrohrzucker', 'trocken', null, 'bio'),
        'bruehe' => ($this->gp)('Rinderbrühe: Pulver, trocken / (Knorr)', 'rinderbruehe', 'trocken', 'Knorr'),
        'schalWuerfel' => ($this->gp)('Schalotte: Würfel 5 mm, frisch', 'schalotte', 'frisch'),
        'schalGanz' => ($this->gp)('Schalotte: ganz, frisch', 'schalotte', 'frisch'),
        'basTk' => ($this->gp)('Basilikum: gehackt, TK', 'basilikum', 'TK'),
        'basFrisch' => ($this->gp)('Basilikum: ganz, frisch', 'basilikum', 'frisch'),
        'sahne15' => ($this->gp)('Sahne: konserviert, 15 % Fett', 'sahne', 'konserviert'),
        'sahne30' => ($this->gp)('Sahne: konserviert, 30 % Fett', 'sahne', 'konserviert'),
        'schalBrunoise' => ($this->gp)('Schalotte: Brunoise, frisch', 'schalotte', 'frisch'),
        'zwiebelGanz' => ($this->gp)('Zwiebel: ganz, frisch', 'zwiebel', 'frisch'),
        'zwiebelTk' => ($this->gp)('Zwiebel: Würfel 10 mm, TK', 'zwiebel', 'TK'),
        'joghurt15' => ($this->gp)('Joghurt: frisch, 1,5 % Fett', 'joghurt', 'frisch'),
        'joghurt35' => ($this->gp)('Joghurt: frisch, 3,5 % Fett', 'joghurt', 'frisch'),
    ];
    $this->m = fn (string $t) => app(IngredientMatchService::class)->matchIngredient($this->rootTeam, $t);
    $this->auto = fn (string $t) => IngredientMatchService::istAutomatischVerdrahtbar(($this->m)($t)) ? (($this->m)($t)['gp_id'] ?? null) : null;
});

it('ohne genannte Variante: nie automatisch Bio, Marke oder Zuschnitt — Basisvariante oder Review', function (string $text, ?string $erwartet) {
    expect(($this->auto)($text))->toBe($erwartet === null ? null : $this->g[$erwartet]->id);
})->with([
    'Rohrohrzucker → nicht automatisch Bio (keine Basis ohne Bio → Review)' => ['Rohrohrzucker', null],
    'Rinderbrühe → nicht automatisch Knorr-Pulver (Review)' => ['Rinderbrühe', null],
    'Schalotten → ganz, nicht Würfel' => ['Schalotten', 'schalGanz'],
    'Basilikum → frisch, nicht gehackt TK' => ['Basilikum', 'basFrisch'],
    'Sahne → Default-GP §5 (30 % Fett), Regel 1' => ['Sahne', 'sahne30'],
    'Joghurt ohne Fettstufe → Review (mehrere Fettstufen, keine Basis)' => ['Joghurt', null],
]);

it('Review statt Automatik trägt die offene Variante mit', function () {
    // 27.10. (d): „Rinderbrühe“ ist seitdem Basiskomponente (Unterrezept/Stub) — die Varianten-Begründung zeigt Rohrohrzucker (nur Bio im Bestand)
    $r = ($this->m)('Rohrohrzucker');

    expect($r['status'])->toBe(MatchBand::FuzzyLow)
        ->and($r['gp_id'])->toBe($this->g['zuckerBio']->id)                    // die Shortlist bleibt, nur nicht automatisch
        ->and($r['variante_offen'] ?? [])->toBe(['Bio']);
});

it('nennt der Text die Variante, gewinnt sie und die Regel stuft nicht herab', function (string $text, string $erwartet) {
    $r = ($this->m)($text);

    expect($r['gp_id'])->toBe($this->g[$erwartet]->id)->and($r)->not->toHaveKey('variante_offen');
})->with([
    'Bio genannt' => ['Bio-Rohrohrzucker', 'zuckerBio'],
    'Fettstufe genannt' => ['Sahne 30 % Fett', 'sahne30'],
    'Marke genannt' => ['Rinderbrühe Pulver Knorr', 'bruehe'],
]);

it('§2 Verarbeitungs-Reduktion: Küchen-Zuschnitt führt auf die Roh-Form, Schnittware nur mit Zukauf-Signal', function (string $text, string $erwartet) {
    expect(($this->m)($text)['gp_id'])->toBe($this->g[$erwartet]->id);
})->with([
    'Schalotten, in Brunoise → roh' => ['Schalotten, in Brunoise', 'schalGanz'],
    'Zwiebeln gewürfelt → roh' => ['Zwiebeln gewürfelt', 'zwiebelGanz'],
    'Zwiebelwürfel 10 mm (TK) → Schnittware TK' => ['Zwiebel Würfel 10 mm (TK)', 'zwiebelTk'],
    'Zwiebelwürfel vorgeschnitten → Schnittware' => ['Zwiebel Würfel 10 mm vorgeschnitten', 'zwiebelTk'],
]);

/** Dominique 27.10. — eigene Ware mit Warengruppe/Unterkategorie. */
function bvGp(int $teamId, string $name, string $slug, ?string $zustand, string $wg, ?string $sub = null, array $extra = []): FoodAlchemistGp
{
    return FoodAlchemistGp::create(['team_id' => $teamId, 'name' => $name, 'gp_key' => 'bvw-' . bin2hex(random_bytes(4)) . '||', 'status' => 'approved',
        'requires_la' => true, 'commodity_group_code' => $wg, 'sub_category' => $sub, 'condition' => $zustand, 'main_ingredient_slug' => $slug] + $extra);
}

it('„gemahlen“ ist bei Gewürzen und Nüssen Zukauf (Variante genannt), „geröstet“ bei Gewürzen Küchenarbeit → Roh-Form', function () {
    $kkGemahlen = bvGp($this->rootTeam->id, 'Kreuzkümmel: gemahlen, trocken', 'kreuzkuemmel', 'trocken', '10', '10.1 Gewürze');
    bvGp($this->rootTeam->id, 'Kreuzkümmel: ganz, trocken', 'kreuzkuemmel', 'trocken', '10', '10.1 Gewürze');
    $mandelGemahlen = bvGp($this->rootTeam->id, 'Mandel: gemahlen, trocken', 'mandel', 'trocken', '12', '12.3 Nüsse & Saaten');
    bvGp($this->rootTeam->id, 'Mandel: ganz, trocken', 'mandel', 'trocken', '12', '12.3 Nüsse & Saaten');
    $korianderGanz = bvGp($this->rootTeam->id, 'Korianderkorn: ganz, trocken', 'korianderkorn', 'trocken', '10', '10.1 Gewürze');
    bvGp($this->rootTeam->id, 'Korianderkorn: geröstet, trocken', 'korianderkorn', 'trocken', '10', '10.1 Gewürze');

    $kk = ($this->m)('Kreuzkümmel gemahlen');
    $ma = ($this->m)('Mandeln gemahlen');
    expect($kk['gp_id'])->toBe($kkGemahlen->id)->and($kk)->not->toHaveKey('variante_offen')
        ->and($ma['gp_id'])->toBe($mandelGemahlen->id)->and($ma)->not->toHaveKey('variante_offen')
        ->and(($this->m)('Korianderkorn geröstet')['gp_id'])->toBe($korianderGanz->id);
});

it('Nüsse „geröstet“: roh UND geröstet im Bestand → Review, nur geröstet → das', function () {
    bvGp($this->rootTeam->id, 'Erdnuss: geröstet, trocken', 'erdnuss', 'trocken', '12', '12.3 Nüsse & Saaten');
    bvGp($this->rootTeam->id, 'Erdnuss: trocken', 'erdnuss', 'trocken', '12', '12.3 Nüsse & Saaten');
    $nurGeroestet = bvGp($this->rootTeam->id, 'Haselnuss: geröstet, trocken', 'haselnuss', 'trocken', '12', '12.3 Nüsse & Saaten');

    expect(($this->m)('Erdnüsse geröstet')['status'])->toBe(MatchBand::FuzzyLow)
        ->and(($this->auto)('Haselnüsse geröstet'))->toBe($nurGeroestet->id);
});

it('haltbare Einzelvariante: Steinpilze ohne Frisch-GP → Review, „getrocknet“ genannt → automatisch; trockene Ware (Linsen) ausgenommen', function () {
    $steinpilz = bvGp($this->rootTeam->id, 'Steinpilz: trocken', 'steinpilz', 'trocken', '01', '01.6 Pilze');
    $linsen = bvGp($this->rootTeam->id, 'Linse: trocken', 'linse', 'trocken', '07');

    expect(($this->auto)('Steinpilze'))->toBeNull()
        ->and(($this->m)('Steinpilze')['gp_id'])->toBe($steinpilz->id)                          // Shortlist bleibt
        ->and(($this->auto)('Steinpilze getrocknet'))->toBe($steinpilz->id)
        ->and(($this->auto)('Linsen'))->toBe($linsen->id);
});

it('Geschwister nur mit derselben Hauptzutat — „Zitronengraspulver“ wird nicht gegen frisches Zitronengras getauscht', function () {
    $pulver = bvGp($this->rootTeam->id, 'Zitronengraspulver: trocken', 'zitronengras', 'trocken', '10', '10.1 Gewürze');
    bvGp($this->rootTeam->id, 'Zitronengras: frisch', 'zitronengras', 'frisch', '03', '03.1 Kräuter');

    expect(($this->m)('Zitronengraspulver')['gp_id'])->toBe($pulver->id);
});

it('(a) einziger Kandidat, Unterschied nur Naturform oder §8-Pflichtangabe → automatisch', function () {
    $t = $this->rootTeam->id;
    $mehl = bvGp($t, 'Weizenmehl: trocken, Type 405', 'weizenmehl', 'trocken', '07', '07.2 Getreide');
    bvGp($t, 'Weizenmehl: trocken, Type 550 / (Bio)', 'weizenmehl', 'trocken', '07', '07.2 Getreide', ['bio' => 'bio']);
    $agar = bvGp($t, 'Agar Agar: Pulver, trocken', 'agar_agar', 'trocken', '12', '12.2 Bindemittel');
    $milch = bvGp($t, 'Vollmilch: frisch, 3,5 % Fett', 'vollmilch', 'frisch', '06', '06.1 Milchprodukte');
    $muskat = bvGp($t, 'Muskat: gemahlen, trocken', 'muskat', 'trocken', '10', '10.1 Gewürze');
    bvGp($t, 'Muskat: gemahlen, trocken / (Bio)', 'muskat', 'trocken', '10', '10.1 Gewürze', ['bio' => 'bio']);
    $salz = bvGp($t, 'Meersalz: grob, trocken', 'meersalz', 'trocken', '10', '10.5 Salze');
    bvGp($t, 'Meersalz: geräuchert, trocken', 'meersalz', 'trocken', '10', '10.5 Salze');
    $kondens = bvGp($t, 'Kondensmilch: konserviert, extra süß', 'kondensmilch', 'konserviert', '06', '06.1 Milchprodukte');

    expect(($this->auto)('Weizenmehl'))->toBe($mehl->id)
        ->and(($this->auto)('Agar-Agar'))->toBe($agar->id)
        ->and(($this->auto)('Vollmilch'))->toBe($milch->id)
        ->and(($this->auto)('Muskat'))->toBe($muskat->id)
        ->and(($this->auto)('Meersalz'))->toBe($salz->id)
        ->and(($this->auto)('Kondensmilch'))->toBe($kondens->id);
});

it('(a) mehrere Kandidaten, die sich nur in der Pflichtangabe unterscheiden → Review; (b) Bio/Marke/Sorte/Herkunft auch einzeln → Review', function () {
    $t = $this->rootTeam->id;
    bvGp($t, 'Sauerrahm: frisch, 10 % Fett', 'sauerrahm', 'frisch', '06', '06.1 Milchprodukte');
    bvGp($t, 'Sauerrahm: frisch, 20 % Fett', 'sauerrahm', 'frisch', '06', '06.1 Milchprodukte');
    bvGp($t, 'Rote Bete Saft: konserviert / (Bio)', 'rote_bete_saft', 'konserviert', '15', null, ['bio' => 'bio']);
    bvGp($t, 'Rotwein: konserviert, Spätburgunder', 'rotwein', 'konserviert', '15');
    bvGp($t, 'Dashi: trocken / (Fueki)', 'dashi', 'trocken', '10', '10.1 Gewürze', ['zusatz' => 'Fueki']);

    expect(($this->m)('Sauerrahm')['status'])->toBe(MatchBand::FuzzyLow)
        ->and(($this->auto)('Rote Bete Saft'))->toBeNull()
        ->and(($this->auto)('Rotwein'))->toBeNull()
        ->and(($this->auto)('Dashi'))->toBeNull();
});

it('(c) Fleisch-Teilstück ist eine genannte Variante, keine Küchenarbeit', function () {
    $t = $this->rootTeam->id;
    bvGp($t, 'Rinderfilet: frisch', 'rinderfilet', 'frisch', '04', '04.1 Rind');
    $mitte = bvGp($t, 'Rinderfilet: Mittelstück, frisch', 'rinderfilet', 'frisch', '04', '04.1 Rind');
    bvGp($t, 'Kalbsrücken: frisch', 'kalbsruecken', 'frisch', '04', '04.1 Kalb');
    $lammMitte = bvGp($t, 'Lammrücken: Mittelstück, frisch, Neuseeland', 'lammruecken', 'frisch', '04', '04.3 Lamm');
    $lamm = bvGp($t, 'Lammrücken: frisch', 'lammruecken', 'frisch', '04', '04.3 Lamm');

    $ohneGp = ($this->m)('Kalbsrücken Mittelstück');
    $herkunft = ($this->m)('Lammrücken Mittelstück');
    expect(($this->auto)('Rinderfilet: frisch, Mittelstueck'))->toBe($mitte->id)
        ->and(($this->auto)('Rinderfilet Mittelstück'))->toBe($mitte->id)
        // kein GP mit dem Teilstück → Review, nie das Stück ohne Teilstück
        ->and($ohneGp['status'])->toBe(MatchBand::FuzzyLow)
        // Teilstück nur mit ungenannter Herkunft → Review auf dem Teilstück-GP, nicht „Lammrücken: frisch“
        ->and($herkunft['status'])->toBe(MatchBand::FuzzyLow)->and($herkunft['gp_id'])->toBe($lammMitte->id)
        ->and(($this->auto)('Lammrücken'))->toBe($lamm->id);
});

it('(a) Pulver/gemahlen ist keine Naturform, wenn es die Ware frisch gibt — und nie bei Nüssen (Messung 27.10.)', function () {
    $t = $this->rootTeam->id;
    bvGp($t, 'Ingwer: gemahlen, trocken', 'ingwer', 'trocken', '10', '10.1 Gewürze');
    $knolle = bvGp($t, 'Ingwer: Knolle, frisch', 'ingwer', 'frisch', '01', '01.2 Wurzelgemüse');
    bvGp($t, 'Tomate: Pulver, trocken', 'tomate', 'trocken', '10', '10.1 Gewürze');
    bvGp($t, 'Tomate: ganz, frisch', 'tomate', 'frisch', '01', '01.1 Fruchtgemüse');
    bvGp($t, 'Haselnuss: gemahlen, trocken', 'haselnuss', 'trocken', '12', '12.3 Nüsse & Saaten');

    expect(($this->auto)('Ingwer'))->toBe($knolle->id)                    // nicht das Pulver
        ->and(($this->auto)('Getrocknete Tomaten'))->toBeNull()          // nicht „Tomate: Pulver“
        ->and(($this->auto)('Haselnüsse'))->toBeNull();                  // Nüsse: gemahlen bleibt Variante
});

it('genanntes Merkmal muss der Treffer tragen — „extra vergine“, „Type 550“, Kochtyp; Zubereitung in der Zeile zählt nicht (Messung 27.10.)', function () {
    $t = $this->rootTeam->id;
    bvGp($t, 'Olivenöl', 'olivenoel', null, '11', '11.1 Öle');
    $ev = bvGp($t, 'Olivenöl: extra vergine', 'olivenoel', null, '11', '11.1 Öle');
    bvGp($t, 'Dinkelmehl: trocken, Type 630', 'dinkelmehl', 'trocken', '07', '07.2 Getreide');
    $t1050 = bvGp($t, 'Dinkelmehl: trocken, Type 1050 / (Bio)', 'dinkelmehl', 'trocken', '07', '07.2 Getreide', ['bio' => 'bio']);
    $kartoffel = bvGp($t, 'Kartoffel: frisch', 'kartoffel', 'frisch', '01', '01.2 Wurzelgemüse');
    $mehlig = bvGp($t, 'Kartoffel: frisch, mehlig', 'kartoffel', 'frisch', '01', '01.2 Wurzelgemüse');
    bvGp($t, 'Kartoffel: gegart, frisch, vakuumiert', 'kartoffel', 'frisch', '01', '01.2 Wurzelgemüse');

    $mehl = ($this->m)('550 g Dinkelmehl Type 1050');
    expect(($this->auto)('Olivenöl extra vergine'))->toBe($ev->id)
        // Type 1050 gibt es nur als Bio → Review auf diesem GP, nie die andere Type
        ->and($mehl['status'])->toBe(MatchBand::FuzzyLow)->and($mehl['gp_id'])->toBe($t1050->id)
        ->and(($this->auto)('Kartoffeln mehlig'))->toBe($mehlig->id)
        // „gegart“ ist Küchenarbeit der Zeile, kein Merkmal
        ->and(($this->auto)('1000 g Kartoffeln (im Meersalz gegart)'))->toBe($kartoffel->id);
});
