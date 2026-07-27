<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * 12·S2a-1b · V-045 (zweiter Halbschritt) — `resolveRecipeAnchorsMany` als Batch-Naht.
 *
 * Zwei Zusicherungen, die der Golden-Test NICHT abdeckt (er friert die *Auswahl* ein,
 * dieser hier die *Kosten* und die *Einzigkeit*):
 *
 * 1. **Konstante Query-Zahl.** Vorher kostete jede Zutaten-Zeile ihre eigenen Mapping-
 *    Lookups — bei 1.000 Gerichten × ~12 Zutaten rund 12.000 Einzel-Queries. Gemessen wird
 *    deshalb nicht ein absoluter Deckel (der bewegt sich mit jedem Eager-Load), sondern die
 *    **Ableitung**: doppelt so viele Gerichte dürfen dieselbe Query-Zahl kosten. Ein
 *    absoluter Wert wäre auf proportionalem Verhalten grün, solange nur die Zahl passt.
 * 2. **Eine Auflösungs-Wahrheit.** `resolveRecipeAnchors` (Einzelfall) delegiert an die
 *    Batch-Variante; beide Wege müssen zeilengleich sein. Sonst rechnet der Pool auf einer
 *    zweiten Auswahl-Logik als die Detail-Anzeige daneben — genau der Riss, den V-018 an
 *    anderer Stelle als teuersten Einzelfall katalogisiert.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->ankerId = [];

    $this->mkAnker = function (string $slug): int {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->ankerId[$slug] = (int) DB::getPdo()->lastInsertId();
    };

    $this->mkGpMapping = function (int $gpId, string $ankerSlug, ?string $conf): void {
        DB::table('foodalchemist_gp_anchor_mappings')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'gp_id' => $gpId, 'anchor_id' => $this->ankerId[$ankerSlug], 'role' => 'kern',
            'source' => 'ai_inferred', 'ai_confidence' => $conf,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $this->mkRezeptMapping = function (int $recipeId, string $ankerSlug, ?string $conf): void {
        DB::table('foodalchemist_recipe_anchor_mappings')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'recipe_id' => $recipeId, 'anchor_id' => $this->ankerId[$ankerSlug], 'role' => 'kern',
            'source' => 'ai_inferred', 'ai_confidence' => $conf,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $this->mkProzessAnker = function (int $recipeId, string $ankerSlug): void {
        DB::table('foodalchemist_recipe_process_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'recipe_id' => $recipeId, 'anchor_id' => $this->ankerId[$ankerSlug],
            'source' => 'ai_inferred', 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $this->mkRezept = fn (string $key, string $name, bool $vk = true) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => $key, 'name' => $name,
        'status' => 'approved', 'is_sales_recipe' => $vk,
    ] + ($vk ? ['sales_net' => 20.00] : []));

    $this->mkSubZutat = function (FoodAlchemistRecipe $recipe, FoodAlchemistRecipe $sub, int $position): void {
        FoodAlchemistRecipeIngredient::create([
            'team_id' => $recipe->team_id, 'recipe_id' => $recipe->id,
            'referenced_recipe_id' => $sub->id, 'raw_text' => $sub->name,
            'quantity' => '100', 'unit_vocab_id' => $this->unitG($this->rootTeam)->id,
            'position' => $position,
        ]);
    };

    foreach (['apfel', 'birne', 'roestaromen', 'fond'] as $slug) {
        ($this->mkAnker)($slug);
    }

    $this->gp = FoodAlchemistGp::create([
        'team_id' => $this->rootTeam->id, 'gp_key' => 'batch|apfel', 'name' => 'Apfel', 'status' => 'approved',
    ]);
    // Mehrdeutig gemappt — der Batch muss dieselbe Zeile gewinnen lassen wie der Einzelfall.
    ($this->mkGpMapping)($this->gp->id, 'birne', '0.500');
    ($this->mkGpMapping)($this->gp->id, 'apfel', null);

    $this->sub = ($this->mkRezept)('batch-sub', 'Basis: Fond', false);
    ($this->mkRezeptMapping)($this->sub->id, 'fond', '0.900');
    ($this->mkProzessAnker)($this->sub->id, 'roestaromen');

    /**
     * Ein Gericht mit allen vier Auflösungs-Wegen in einer Zeile-Garnitur: GP-Mapping,
     * Sub-Rezept-Mapping (+ dessen Prozess-Anker), ungemappter `raw_text` und ein
     * Eigen-Zustands-Anker am Gericht selbst. Nur so misst der Konstanz-Riegel alle vier
     * Batch-Karten — ein zutatenloses Fixture wäre grün, ohne die Ebene zu berühren, auf
     * die es ankommt (die Mapping-Lookups JE ZUTAT).
     */
    $this->mkGericht = function (string $key) {
        $r = ($this->mkRezept)($key, 'HG: ' . $key);
        $this->makeIngredient($r, 'Apfel', $this->gp, '100', 1);
        $this->makeIngredient($r, 'Sonstwas ohne GP', null, '50', 2);
        ($this->mkSubZutat)($r, $this->sub, 3);
        ($this->mkProzessAnker)($r->id, 'roestaromen');

        return $r;
    };
});

/** Frische Instanz je Messung: `anchorIndex` und der `neutral`-Lookup sind je Instanz memoisiert. */
function batchMessung(callable $arbeit): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $arbeit();
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $n;
}

it('V-045: doppelt so viele Gerichte kosten dieselbe Query-Zahl (konstant, nicht proportional)', function () {
    $vier = collect(range(1, 4))->map(fn ($i) => ($this->mkGericht)("k4-{$i}"))->all();
    $acht = collect(range(1, 8))->map(fn ($i) => ($this->mkGericht)("k8-{$i}"))->all();

    $messe = function (array $rezepte) {
        $svc = new PairingService;
        $ids = array_map(fn ($r) => $r->id, $rezepte);
        // Frisch aus der DB holen, damit keine Relation vorgeladen ist — das ist der teure
        // Fall (der Pool lädt im Default-Modus KEINE Zutaten eager) und damit der ehrliche.
        $frisch = FoodAlchemistRecipe::whereIn('id', $ids)->get();
        $svc->resolveRecipeAnchorsMany($frisch);            // Warmlauf: Memoisierung + Index

        $frisch2 = FoodAlchemistRecipe::whereIn('id', $ids)->get();

        return batchMessung(fn () => $svc->resolveRecipeAnchorsMany($frisch2));
    };

    // Gemessen 2026-07-27: **7** Queries für vier UND für acht Gerichte (Zutaten + gp +
    // referencedRecipe + zwei Mapping-Tabellen + zwei Prozess-Anker-Reads). Unter der
    // Gegenprobe-Mutation „je Gericht auflösen" waren es 28 bzw. 56 — 7 pro Gericht.
    expect($messe($acht))->toBe($messe($vier));
});

it('V-045: Batch- und Einzel-Weg liefern zeilengleich dasselbe (eine Auflösungs-Wahrheit)', function () {
    $gerichte = collect(range(1, 3))->map(fn ($i) => ($this->mkGericht)("gleich-{$i}"))->all();
    $ids = array_map(fn ($r) => $r->id, $gerichte);

    $svc = app(PairingService::class);
    $batch = $svc->resolveRecipeAnchorsMany(FoodAlchemistRecipe::whereIn('id', $ids)->get());

    foreach ($ids as $id) {
        $einzel = $svc->resolveRecipeAnchors(FoodAlchemistRecipe::findOrFail($id));
        // `prozess` normalisieren: die zugrunde liegenden Reads haben kein ORDER BY (V-057),
        // ihre Reihenfolge ist Treiber-Zufall und darf hier nicht als Unterschied gelten.
        $norm = function (array $zeilen) {
            return array_map(function (array $z) {
                $p = array_map('intval', $z['prozess']);
                sort($p);

                return ['label' => $z['label'], 'kern' => $z['kern'] === null ? null : (int) $z['kern'], 'prozess' => $p, 'via' => $z['via']];
            }, $zeilen);
        };

        expect($norm($batch[$id]))->toEqualCanonicalizing($norm($einzel))
            ->and(count($batch[$id]))->toBe(count($einzel));
    }
});

it('V-045: die Mehrdeutigkeit entscheidet im Batch wie im Einzelfall — NULL-Konfidenz gewinnt', function () {
    $r = ($this->mkGericht)('mehrdeutig');

    $svc = app(PairingService::class);
    $zeilen = $svc->resolveRecipeAnchorsMany([$r])[$r->id];

    // Zeile 1 = die GP-Zutat: `apfel` (ai_confidence NULL ⇒ COALESCE 1.0) schlägt `birne` (0.5),
    // obwohl `birne` die kleinere `id` hat. Genau diese Regel würde ein PHP-Nachbau kippen.
    expect((int) $zeilen[0]['kern'])->toBe($this->ankerId['apfel'])
        ->and($zeilen[0]['via'])->toBe('gp_anker');
});

it('V-045: leere Eingabe kostet keine Query und liefert ein leeres Ergebnis', function () {
    $svc = app(PairingService::class);

    $n = batchMessung(fn () => expect($svc->resolveRecipeAnchorsMany([]))->toBe([]));

    expect($n)->toBe(0);
});
