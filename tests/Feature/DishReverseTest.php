<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\DishReverseService;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * R6.9 — Dish-Reverse-Engineering: fremdes Gericht (Text) → GP-Zerlegung →
 * Aroma-Skelett → Nachbau aus eigenem Bestand + Lücken. Unmatched ohne LA =
 * Beschaffungs-Wunsch (kein Raten).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->svc = app(DishReverseService::class);

    $mkAnker = function (string $slug) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert([
            'uuid' => (string) UuidV7::generate(), 'slug' => $slug, 'display_de' => ucfirst($slug),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::getPdo()->lastInsertId();
    };
    $this->erdig = $mkAnker('erdig');
    $this->nussig = $mkAnker('nussig');
    $this->kaesig = $mkAnker('kaesig');

    foreach ([[$this->erdig, $this->nussig, 'erprobt']] as [$a, $b, $typ]) {
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            DB::table('foodalchemist_pairing_anchor_edges')->insert([
                'uuid' => (string) UuidV7::generate(), 'anchor_a_id' => $x, 'anchor_b_id' => $y,
                'type' => $typ, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    $mkGp = function (string $name, int $ankerId) {
        $gp = $this->makeGp($this->rootTeam, $name);
        DB::table('foodalchemist_gp_anchor_mappings')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'gp_id' => $gp->id, 'anchor_id' => $ankerId, 'role' => 'kern',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $gp;
    };
    $mkGp('Rote Bete', $this->erdig);
    $mkGp('Walnuss', $this->nussig);
    $mkGp('Ziegenkaese', $this->kaesig);

    // Eigenes VK-Gericht, das erdig+nussig trägt → Nachbau-Kandidat.
    $this->vk = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'rbsalat', 'name' => 'Rote-Bete-Salat mit Walnuss',
        'status' => 'approved', 'is_sales_recipe' => true,
    ]);
    foreach ([$this->erdig, $this->nussig] as $aid) {
        DB::table('foodalchemist_recipe_anchor_mappings')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'recipe_id' => $this->vk->id, 'anchor_id' => $aid, 'role' => 'kern',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
});

it('zerlegt Text in GPs, baut Aroma-Skelett + Nachbau-Kandidaten, Unbekanntes → Beschaffungs-Wunsch', function () {
    $out = $this->svc->reverse($this->rootTeam, 'Rote Bete, Walnuss und Ziegenkaese auf Quallensalat', 8);

    $erkannteNamen = array_column($out['komponenten']['erkannt'], 'name');
    expect($erkannteNamen)->toContain('Rote Bete')
        ->and($erkannteNamen)->toContain('Walnuss');

    // Unbekanntes ohne LA → Beschaffungs-Wunsch, kein Raten.
    $wunschPhrasen = array_map(fn ($w) => mb_strtolower($w['phrase']), $out['komponenten']['beschaffungs_wuensche']);
    expect($wunschPhrasen)->toContain('quallensalat');

    // Aroma-Skelett: tragende Anker + die erdig–nussig-Verbund-Kante.
    expect($out['aroma_skelett']['traeger_anker'])->not->toBe([]);
    $kanten = collect($out['aroma_skelett']['verbund_kanten'])
        ->map(fn ($k) => collect([$k['a'], $k['b']])->sort()->values()->all());
    expect($kanten->contains(collect(['Erdig', 'Nussig'])->sort()->values()->all()))->toBeTrue();

    // Rekonstruktion: eigenes VK-Gericht wird gefunden (teilt >=2 Anker).
    $kand = collect($out['rekonstruktion']['kandidaten'])->firstWhere('recipe_id', $this->vk->id);
    expect($kand)->not->toBeNull()
        ->and($kand['shared_anker'])->toBeGreaterThanOrEqual(2);
});

it('meldet Lücken: Anker, den kein Bestandsgericht trägt', function () {
    // Nur Ziegenkaese (kaesig) — kein VK-Gericht trägt kaesig → Lücke.
    $out = $this->svc->reverse($this->rootTeam, 'Ziegenkaese', 8);

    $luecken = array_column($out['rekonstruktion']['luecken'], 'name');
    expect($luecken)->toContain('Kaesig');
});
