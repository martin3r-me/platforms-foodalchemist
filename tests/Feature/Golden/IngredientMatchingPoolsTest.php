<?php

use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Enums\MatchBand;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\IngredientMatchService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * M4-09: GL-04 Voll-Port, DB-Teil — Pool-Priorität (4.4k/l), Tiebreaker-Integration
 * (4.4m/r), Containment-Floor (4.4o), Spezifitäts-Guard-Routing (4.4q),
 * §4-/§5-Alias end-to-end (4.4n/s), Shortlist (4.4p). 1:1 aus recipe_matching.rs.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(IngredientMatchService::class);

    // Stub: Sub-Typ-Vokabular (echte Tabelle folgt mit V-20) — der 4.4b-Boost liest legacy_id→slug
    if (! Schema::hasTable('foodalchemist_vocab_sub_rezept_typ')) {
        Schema::create('foodalchemist_vocab_sub_rezept_typ', function ($table) {
            $table->id();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->string('slug');
            $table->timestamps();
        });
    }
    foreach ([['legacy_id' => 1, 'slug' => 'karamell'], ['legacy_id' => 2, 'slug' => 'paste'], ['legacy_id' => 3, 'slug' => 'marinade']] as $typ) {
        \Illuminate\Support\Facades\DB::table('foodalchemist_vocab_sub_rezept_typ')->insert([...$typ, 'created_at' => now(), 'updated_at' => now()]);
    }

    $this->mkGp = function (string $name, ?string $slug = null, ?string $zustand = null, ?string $bio = null) {
        $gp = $this->makeGp($this->rootTeam, $name);
        $gp->update(['main_ingredient_slug' => $slug, 'status' => 'approved', 'condition' => $zustand, 'bio' => $bio]);

        return $gp->refresh();
    };
    $this->mkSub = fn (string $name, ?int $typLegacyId = null) => FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => mb_strtolower(str_replace([' ', ':'], '_', $name)) . '_' . uniqid(),
        'name' => $name, 'status' => 'approved', 'sub_recipe_type_legacy_id' => $typLegacyId,
    ]);
    $this->match = fn (string $name, ?string $slug = null, string $mode = 'gp_first', string $pref = 'neutral', bool $raw = false, string $bio = 'neutral') => $this->svc->matchIngredient($this->rootTeam, $name, $slug, $mode, $pref, $raw, $bio);
});

// ──── 4.4b: Sub-Typ-Boost mit DB ─────────────────────────────────────────

it('subrecipe_boost_karamell_outranks_untagged', function () {
    ($this->mkSub)('Walnuss-Karamell', 1);
    ($this->mkSub)('Walnuss Öl');

    $m = ($this->match)('karamellisierte Walnuss', null, 'sub_recipe_first');
    expect($m['recipe_name'])->toBe('Walnuss-Karamell')
        ->and($m['score'])->toBeGreaterThanOrEqual(0.5);
});

it('subrecipe_boost_pesto_finds_paste_tagged', function () {
    ($this->mkSub)('Basilikum Pesto Genovese', 2);
    ($this->mkSub)('Basilikum Vinaigrette');

    $m = ($this->match)('Basilikum Pesto', null, 'sub_recipe_first');
    expect($m['recipe_name'])->toBe('Basilikum Pesto Genovese');
});

// ──── 4.4k: Pool-Priorität ───────────────────────────────────────────────

it('gp_first_prefers_konzentrat_gp + subrecipe_first_prefers_basisrezept', function () {
    ($this->mkGp)('Kalbsfond: konserviert, Konzentrat', 'kalbsfond');
    ($this->mkSub)('Heller Kalbsfond', 1);

    $gpFirst = ($this->match)('Kalbsfond', 'kalbsfond', 'gp_first');
    expect($gpFirst['target'])->toBe('gp')
        ->and($gpFirst['gp_name'])->toBe('Kalbsfond: konserviert, Konzentrat');

    $subFirst = ($this->match)('Kalbsfond', 'kalbsfond', 'sub_recipe_first');
    expect($subFirst['target'])->toBe('sub_recipe')
        ->and($subFirst['recipe_name'])->toBe('Heller Kalbsfond');
});

it('gpfirst_exact_sub_overrides_convenience_gp', function () {
    ($this->mkGp)('Kalbsfond: konserviert, Konzentrat', 'kalbsfond');
    ($this->mkSub)('Kalbsfond');

    $m = ($this->match)('Kalbsfond', 'kalbsfond', 'gp_first');
    expect($m['target'])->toBe('sub_recipe')
        ->and($m['recipe_name'])->toBe('Kalbsfond');
});

it('gpfirst_weak_sub_does_not_override_gp', function () {
    ($this->mkGp)('Kalbsfond: konserviert, Konzentrat', 'kalbsfond');
    ($this->mkSub)('Heller Kalbsfond', 1);

    $m = ($this->match)('Kalbsfond', 'kalbsfond', 'gp_first');
    expect($m['target'])->toBe('gp');
});

it('subrecipe_first_grundzutat_stays_gp (Halbfabrikat-Gate)', function () {
    ($this->mkGp)('Rotwein: trocken, Spätburgunder', 'rotwein');
    ($this->mkSub)('Rotwein Vinaigrette', 3);

    $m = ($this->match)('Rotwein', 'rotwein', 'sub_recipe_first');
    expect($m['target'])->toBe('gp');
});

it('subrecipe_first_falls_back_to_gp_when_no_subrecipe', function () {
    ($this->mkGp)('Zwiebeln: frisch, geschaelt', 'zwiebeln');

    $m = ($this->match)('Zwiebeln', 'zwiebeln', 'sub_recipe_first');
    expect($m['target'])->toBe('gp');
});

// ──── 4.4l/m: Tiebreaker-Integration ─────────────────────────────────────

it('tiebreaker: FreshFirst/FrozenFirst/PreservedFirst/Neutral über Karotten-Varianten', function () {
    ($this->mkGp)('Karotten: TK, Baby', 'karotten');                      // niedrigste id zuerst!
    ($this->mkGp)('Karotten: frisch, geschaelt', 'karotten');
    ($this->mkGp)('Speisesalz: Portionssticks', 'salz');
    ($this->mkGp)('Speisesalz: jodiert', 'salz');

    expect(($this->match)('Karotten', 'karotten', 'gp_first', 'fresh_first')['gp_name'])->toBe('Karotten: frisch, geschaelt')
        ->and(($this->match)('Karotten', 'karotten', 'gp_first', 'preserved_first')['gp_name'])->toBe('Karotten: TK, Baby')
        ->and(($this->match)('Karotten', 'karotten', 'gp_first', 'frozen_first')['gp_name'])->toBe('Karotten: TK, Baby')
        ->and(($this->match)('Karotten', 'karotten')['gp_name'])->toBe('Karotten: TK, Baby')   // Neutral = Legacy: erster Max
        ->and(($this->match)('Salz', 'salz', 'gp_first', 'fresh_first')['gp_name'])->toBe('Speisesalz: jodiert');
});

it('tiebreaker: Drei-Zustand-Pool (konserviert/TK/frisch) diskriminiert alle Pole', function () {
    ($this->mkGp)('Tomaten: konserviert, geschaelt', 'tomaten');
    ($this->mkGp)('Tomaten: TK', 'tomaten');
    ($this->mkGp)('Tomaten: frisch', 'tomaten');

    expect(($this->match)('Tomaten', 'tomaten', 'gp_first', 'fresh_first')['gp_name'])->toBe('Tomaten: frisch')
        ->and(($this->match)('Tomaten', 'tomaten', 'gp_first', 'frozen_first')['gp_name'])->toBe('Tomaten: TK')
        ->and(($this->match)('Tomaten', 'tomaten', 'gp_first', 'preserved_first')['gp_name'])->toBe('Tomaten: konserviert, geschaelt');
});

// ──── 4.4r: Bio-Integration ──────────────────────────────────────────────

it('bio_conventional_picks_non_bio_gp (+ Gegenprobe Bio-Pref)', function () {
    ($this->mkGp)('Eigelb: frisch, fluessig, Bio', 'eigelb');
    ($this->mkGp)('Eigelb: frisch, fluessig', 'eigelb');

    expect(($this->match)('Eigelb', 'eigelb', 'gp_first', 'fresh_first', false, 'conventional')['gp_name'])
        ->toBe('Eigelb: frisch, fluessig');
    expect(($this->match)('Eigelb', 'eigelb', 'gp_first', 'fresh_first', false, 'bio')['gp_name'])
        ->toBe('Eigelb: frisch, fluessig, Bio');
});

// ──── 4.4q: Spezifitäts-Guard-Routing ────────────────────────────────────

it('specificity_guard_routes_to_specific_gp', function () {
    ($this->mkGp)('Salz: trocken, raffiniert, jodiert', 'salz');
    ($this->mkGp)('Meersalz: trocken, grob', 'meersalz');

    $m = ($this->match)('Meersalz', 'salz', 'gp_first', 'fresh_first');
    expect($m['gp_name'])->toBe('Meersalz: trocken, grob');
});

// ──── 4.4o: Containment-Floor-Integration ────────────────────────────────

it('containment_floor_greens_gp_without_slug', function () {
    ($this->mkGp)('Rinderhackfleisch: frisch', 'rinderhackfleisch');

    $m = ($this->match)('Rinderhackfleisch');
    expect($m['target'])->toBe('gp')
        ->and($m['score'])->toBeGreaterThanOrEqual(0.85)
        ->and($m['status'])->toBe(MatchBand::Exact);
});

it('containment_floor_greens_subrecipe_without_slug', function () {
    ($this->mkSub)('Tomatensugo (klassisch)');

    $m = ($this->match)('Tomatensugo', null, 'sub_recipe_first');
    expect($m['recipe_name'])->toBe('Tomatensugo (klassisch)')
        ->and($m['score'])->toBeGreaterThanOrEqual(0.85);
});

it('containment_floor_does_not_green_partial_compound', function () {
    ($this->mkGp)('Eierlikör: Sahne, Whisky, Dessert', 'eierlikoer');

    $m = ($this->match)('Eier');
    expect($m['status'])->not->toBe(MatchBand::Exact);
});

// ──── 4.4p: Shortlist ────────────────────────────────────────────────────

it('candidates_for_returns_both_pools_ranked', function () {
    ($this->mkGp)('Karotten: TK, Baby', 'karotten');
    ($this->mkGp)('Karotten: frisch, geschaelt', 'karotten');
    ($this->mkSub)('Karotten Vinaigrette');

    $cands = $this->svc->candidatesFor($this->rootTeam, 'Karotten', 'karotten', 8);

    expect(count($cands))->toBeGreaterThanOrEqual(3);
    for ($i = 1; $i < count($cands); $i++) {
        expect($cands[$i - 1]['score'])->toBeGreaterThanOrEqual($cands[$i]['score']);
    }
    expect(collect($cands)->pluck('kind')->unique()->sort()->values()->all())->toBe(['gp', 'sub'])
        ->and(collect($cands)->every(fn ($c) => str_starts_with($c['reference'], 'gp:') || str_starts_with($c['reference'], 'sub:')))->toBeTrue();
});

it('candidates_for_recalls_compound_noun (Fix-C-Beleg)', function () {
    ($this->mkGp)('Rinderhackfleisch: frisch', 'rinderhackfleisch');

    $strict = ($this->match)('Hackfleisch vom Rind');
    expect($strict['target'])->toBe('none');                              // strikter Matcher verfehlt

    $cands = $this->svc->candidatesFor($this->rootTeam, 'Hackfleisch vom Rind', null, 8);
    expect(collect($cands)->contains(fn ($c) => $c['kind'] === 'gp' && $c['name'] === 'Rinderhackfleisch: frisch'))->toBeTrue();
});

it('candidates_for_empty_on_no_signal', function () {
    ($this->mkGp)('Karotten: frisch, geschaelt', 'karotten');

    expect($this->svc->candidatesFor($this->rootTeam, 'Drachenfrucht', 'drachenfrucht', 8))->toBeEmpty();
});

// ──── 4.4s: §5-Alias end-to-end ──────────────────────────────────────────

it('alias_salz_routes_to_unjodiert_kochsalz (+ Fallback wenn Ziel fehlt)', function () {
    ($this->mkGp)('Salz: trocken, raffiniert, jodiert', 'salz');
    ($this->mkGp)('Salz / Kochsalz: trocken, unjodiert, Raffinade', 'salz_kochsalz');

    expect(($this->match)('Salz', 'salz')['gp_name'])->toBe('Salz / Kochsalz: trocken, unjodiert, Raffinade');

    // Fallback: Kochsalz-GP löschen ⇒ normales Matching greift
    \Platform\FoodAlchemist\Models\FoodAlchemistGp::where('name', 'like', '%Kochsalz%')->forceDelete();
    expect(($this->match)('Salz', 'salz')['gp_name'])->toBe('Salz: trocken, raffiniert, jodiert');
});

// ──── 4.4n: §4-Alias end-to-end (der Consommé-Bug) ───────────────────────

it('alias_rinderbruehe_routes_to_kalbsfond_not_gp', function () {
    ($this->mkGp)('Rinderbrühe: konserviert, Konzentrat', 'rinderbruehe');
    ($this->mkSub)('HELLER KALBSFOND');
    ($this->mkSub)('BRAUNER KALBSFOND');
    ($this->mkSub)('HELLER KRUSTENTIERFOND');
    ($this->mkSub)('DUNKLER GEFLÜGELFOND');

    $m = ($this->match)('Rinderbrühe', 'rinderbruehe', 'gp_first', 'fresh_first');
    expect($m['target'])->toBe('sub_recipe')
        ->and($m['recipe_name'])->toBe('HELLER KALBSFOND')
        ->and(abs($m['score'] - 0.95))->toBeLessThan(0.001);
});

it('alias_falls_back_when_target_missing', function () {
    ($this->mkGp)('Rinderbrühe: konserviert, Konzentrat', 'rinderbruehe');

    $m = ($this->match)('Rinderbrühe', 'rinderbruehe', 'gp_first', 'fresh_first');
    expect($m['target'])->toBe('gp');
});
