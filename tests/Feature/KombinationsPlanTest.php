<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\GenerationContextService;
use Platform\FoodAlchemist\Services\Pairing\KombinationsPlan;
use Platform\FoodAlchemist\Services\Pairing\KontrastAbleitung;
use Platform\FoodAlchemist\Services\Pairing\RezeptProfil;
use Platform\FoodAlchemist\Tests\Support\Harmonie;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 60 · P7b: Kombinationsplan für den Generator — Leit-Aromen exakt, Harmonie nur 3★,
 * Bedarfe mit Lieferanten, Konflikte, beim Gericht passende Basisrezepte.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->a = [];
    $mk = function (string $slug, string $name) {
        DB::table('foodalchemist_vocab_pairing_anchors')->insert(['uuid' => (string) UuidV7::generate(), 'slug' => $slug,
            'display_de' => $name, 'aroma_intensitaet' => 1.0, 'created_at' => now(), 'updated_at' => now()]);

        return $this->a[$slug] = (int) DB::getPdo()->lastInsertId();
    };
    foreach (['pumpkin' => 'Kürbis', 'sage' => 'Salbei', 'walnut' => 'Walnuss', 'rice_vinegar' => 'Reisessig', 'anise' => 'Anis'] as $s => $n) {
        $mk($s, $n);
    }
    Harmonie::kante($this->a['pumpkin'], $this->a['sage'], 3);
    Harmonie::kante($this->a['pumpkin'], $this->a['walnut'], 2);              // nur „passt" → nicht in der Palette
    $w = fn (string $t, array $z) => DB::table($t)->insert($z + ['status' => 'entwurf', 'created_at' => now(), 'updated_at' => now()]);
    $w('foodalchemist_anchor_bedarfe', ['anchor_id' => $this->a['pumpkin'], 'achse' => 'saeure', 'staerke' => 'muss']);
    $w('foodalchemist_anchor_eigenschaften', ['anchor_id' => $this->a['rice_vinegar'], 'achse' => 'saeure', 'stufe' => 3, 'quelle' => 'dossier']);
    DB::table('foodalchemist_anchor_beziehungen')->insert(['anchor_a_id' => $this->a['pumpkin'], 'anchor_b_id' => $this->a['anise'],
        'art' => 'konflikt', 'achse' => '', 'grundlage' => 'dossier', 'status' => 'entwurf', 'created_at' => now(), 'updated_at' => now()]);
    app(KontrastAbleitung::class)->baue();
    $this->plan = fn (array $w, bool $gericht = false) => app(KombinationsPlan::class)->fuer($this->rootTeam, $w, $gericht);
});

it('Leit-Aroma: Harmonie nur 3★, Bedarf mit Lieferant, Konflikt zum Vermeiden', function () {
    $p = ($this->plan)(['kürbis']);

    expect($p['leit_aromen'])->toHaveCount(1)
        ->and($p['leit_aromen'][0]['aroma'])->toBe('Kürbis')
        ->and($p['leit_aromen'][0]['harmonie'])->toBe(['Salbei'])
        ->and($p['leit_aromen'][0]['braucht'])->toBe([['achse' => 'Säure', 'staerke' => 'muss', 'liefern' => ['Reisessig']]])
        ->and($p['leit_aromen'][0]['vermeiden'])->toBe(['Anis'])
        ->and($p)->not->toHaveKey('komponenten');
});

it('kein Wortteil-Raten: nur exakt, Synonym oder Zerlegung an einem bekannten Wortkopf (Spec 80 A5)', function () {
    // „Salbeibutter" = Salbei + Kopf „butter" → Leit-Aroma Salbei (gewollt seit Spec 80 Paket 2).
    expect(array_column(($this->plan)(['salbeibutter'])['leit_aromen'], 'aroma'))->toBe(['Salbei'])
        // „Kürbiskernöl" zerlegt zu „kürbiskern" + „öl" — kein Anker, also kein Kürbis durch Teilwort.
        ->and(($this->plan)(['kürbiskernöl']))->toBeNull()
        ->and(($this->plan)(['xyz']))->toBeNull();
});

it('Gericht: ein Basisrezept aus dem Bestand, das den offenen Bedarf deckt, wird als Komponente angeboten', function () {
    $gp = $this->makeGp($this->rootTeam, 'Reisessig');
    DB::table('foodalchemist_gp_anchor_mappings')->insert(['uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'gp_id' => $gp->id, 'anchor_id' => $this->a['rice_vinegar'], 'role' => 'kern', 'created_at' => now(), 'updated_at' => now()]);
    Harmonie::kante($this->a['pumpkin'], $this->a['rice_vinegar'], 3);
    $gel = $this->makeRecipe($this->rootTeam, 'Gel: Reisessig');
    $gel->update(['is_sales_recipe' => false, 'function' => 'Garnitur', 'taste_direction' => 'herzhaft']);
    $this->makeIngredient($gel, 'Reisessig', $gp, '100', 1);
    app(RezeptProfil::class)->fuer($gel->id);

    $p = ($this->plan)(['kürbis'], true);

    expect($p['rolle'])->toBe('komposition')
        ->and($p['komponenten'])->toBe([['deckt' => 'Säure', 'basisrezepte' => [['sub_rezept_id' => $gel->id, 'name' => 'Gel: Reisessig']]]]);
});

it('Generator-Kontext: kombinationsplan ersetzt die alte Partnerliste', function () {
    $out = app(GenerationContextService::class)->forGeneration($this->rootTeam, 'Kürbis mit Salbei', false);

    expect($out)->toHaveKey('kombinationsplan')
        ->and($out)->not->toHaveKey('pairing')
        ->and(array_column($out['kombinationsplan']['leit_aromen'], 'aroma'))->toContain('Kürbis');
});
