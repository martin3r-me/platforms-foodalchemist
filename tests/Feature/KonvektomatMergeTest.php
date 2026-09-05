<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * „Combi-Steamer" und „Konvektomat" sind dasselbe Gerät. Zwei Namen für eine Maschine hiessen:
 * die Füllgrad-Matrix (Behälter × Gerät) zweimal pflegen — und die Hälfte der Rezepte hängt an
 * der ungepflegten Zeile.
 */
beforeEach(function () {
    $this->geraet = fn (string $name, ?int $teamId = null) => DB::table('foodalchemist_vocab_regeneration_devices')
        ->insertGetId([
            'uuid' => (string) Str::uuid7(), 'team_id' => $teamId ?? $this->rootTeam->id,
            'slug' => Str::slug($name, '_'), 'name' => $name, 'sort_order' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

    $this->merge = fn () => (require dirname(__DIR__, 2)
        .'/database/migrations/2026_09_05_000002_merge_combi_steamer_in_konvektomat.php')->up();
});

it('führt Combi-Steamer auf den Konvektomat zusammen und hängt die Regenerationszeilen um', function () {
    $this->seedTeamHierarchy();

    $konvektomat = ($this->geraet)('Konvektomat');
    $combi = ($this->geraet)('Combi-Steamer');
    $bain = ($this->geraet)('Bain Marie');

    $rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'r-merge-g', 'name' => 'Gratin: Kartoffel', 'status' => 'approved',
    ]);
    DB::table('foodalchemist_recipe_regenerations')->insert([
        'uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id, 'recipe_id' => $rezept->id,
        'component_label' => 'Gesamt', 'device_vocab_id' => $combi, 'sort_order' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    ($this->merge)();

    $geraete = DB::table('foodalchemist_vocab_regeneration_devices')->whereIn('id', [$konvektomat, $combi, $bain])
        ->get()->keyBy('id');

    expect($geraete[$combi]->deleted_at)->not->toBeNull()
        ->and($geraete[$konvektomat]->deleted_at)->toBeNull()
        ->and($geraete[$bain]->deleted_at)->toBeNull()     // ein anderes Gerät bleibt ein anderes Gerät
        // Die Regenerationszeile zeigt jetzt auf den Konvektomat — sonst zeigte sie ins Leere.
        ->and((int) DB::table('foodalchemist_recipe_regenerations')->where('recipe_id', $rezept->id)
            ->value('device_vocab_id'))->toBe($konvektomat);
});

it('lässt ein Team ohne Konvektomat unangetastet — es gibt kein Ziel zum Zusammenführen', function () {
    $this->seedTeamHierarchy();

    $nurCombi = ($this->geraet)('Combi-Steamer', $this->childA->id);

    ($this->merge)();

    expect(DB::table('foodalchemist_vocab_regeneration_devices')->where('id', $nurCombi)->value('deleted_at'))
        ->toBeNull();
});
