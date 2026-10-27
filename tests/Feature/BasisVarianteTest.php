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
    $r = ($this->m)('Rinderbrühe');

    expect($r['status'])->toBe(MatchBand::FuzzyLow)
        ->and($r['gp_id'])->toBe($this->g['bruehe']->id)                       // die Shortlist bleibt, nur nicht automatisch
        ->and(implode(' ', $r['variante_offen'] ?? []))->toContain('Marke: Knorr');
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
function bvGp(int $teamId, string $name, string $slug, ?string $zustand, string $wg, ?string $sub = null): FoodAlchemistGp
{
    return FoodAlchemistGp::create(['team_id' => $teamId, 'name' => $name, 'gp_key' => 'bvw-' . bin2hex(random_bytes(4)) . '||', 'status' => 'approved',
        'requires_la' => true, 'commodity_group_code' => $wg, 'sub_category' => $sub, 'condition' => $zustand, 'main_ingredient_slug' => $slug]);
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
