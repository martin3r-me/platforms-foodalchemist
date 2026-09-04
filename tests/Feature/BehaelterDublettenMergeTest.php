<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Platform\FoodAlchemist\Console\BehaelterKatalogCommand;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 51 — die Slug-Falle, aufgefallen am Echtbestand von demo.
 *
 * Der WaWi-Import schreibt `gn_1_1_65mm`, der Katalog-Seed `gn_11_65mm` — verschiedene Slugs,
 * derselbe Behaelter. Der Seed entdoppelte gegen den Slug und legte 16 GN-Groessen ein zweites
 * Mal an. Die Reparatur fuehrt sie auf die niedrigste ID zusammen (die referenzierte).
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->behaelter = function (string $slug, string $name, array $extra = []): int {
        return DB::table('foodalchemist_vocab_containers')->insertGetId([
            'uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id,
            'slug' => $slug, 'name' => $name, 'sort_order' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ] + $extra);
    };

    $this->merge = fn () => (require dirname(__DIR__, 2)
        .'/database/migrations/2026_09_04_000015_merge_behaelter_namens_dubletten.php')->up();
});

it('fuehrt namensgleiche Behaelter auf die niedrigste ID zusammen und haengt Referenzen um', function () {
    // Bestandszeile: kennt ihr Volumen, aber keine Tiefe (der Import hat keine Masse mitgebracht).
    $behalt = ($this->behaelter)('gn_1_1_65mm', 'GN 1/1 65mm', ['volumen_l' => 8.80]);
    $dublette = ($this->behaelter)('gn_11_65mm', 'GN 1/1 65mm', ['volumen_l' => 8.80, 'tiefe_mm' => 65, 'familie' => 'GN']);
    $fremd = ($this->behaelter)('gn_11_100mm', 'GN 1/1 100mm', ['volumen_l' => 13.70]);

    $rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'r-merge', 'name' => 'Suppe: Tomate', 'status' => 'approved',
    ]);
    DB::table('foodalchemist_recipe_containers')->insert([
        'uuid' => (string) Str::uuid7(), 'team_id' => $this->rootTeam->id, 'recipe_id' => $rezept->id,
        'zweck' => 'abfuellen', 'container_vocab_id' => $dublette,   // zeigt auf die Dublette
        'skalierung' => 'hoehe_gebunden', 'created_at' => now(), 'updated_at' => now(),
    ]);

    ($this->merge)();

    $nach = DB::table('foodalchemist_vocab_containers')->whereIn('id', [$behalt, $dublette, $fremd])->get()->keyBy('id');

    expect($nach[$dublette]->deleted_at)->not->toBeNull()
        ->and($nach[$behalt]->deleted_at)->toBeNull()
        ->and($nach[$fremd]->deleted_at)->toBeNull()
        // Die Luecke im Behalt-Datensatz wird aus der Dublette gefuellt, der gesetzte Wert bleibt.
        ->and((int) $nach[$behalt]->tiefe_mm)->toBe(65)
        ->and((float) $nach[$behalt]->volumen_l)->toBe(8.80)
        // Die Referenz zeigt auf den Behalt-Datensatz — sonst zeigte sie auf eine geloeschte Zeile.
        ->and((int) DB::table('foodalchemist_recipe_containers')->where('recipe_id', $rezept->id)
            ->value('container_vocab_id'))->toBe($behalt);
});

it('laesst zwei verschiedene Behaelter mit aehnlichem Namen in Ruhe', function () {
    // Gleicher Name, aber widersprechendes Nennvolumen: dann sind es zwei Dinge, kein Dublettenpaar.
    $a = ($this->behaelter)('kiste_gross', 'Kiste', ['volumen_l' => 30.0]);
    $b = ($this->behaelter)('kiste_klein', 'Kiste', ['volumen_l' => 12.0]);

    ($this->merge)();

    expect(DB::table('foodalchemist_vocab_containers')->whereIn('id', [$a, $b])
        ->whereNull('deleted_at')->count())->toBe(2);
});

it('erkennt dieselbe Groesse unter jeder Schreibweise', function () {
    $k = fn (string $n) => BehaelterKatalogCommand::namensSchluessel($n);

    expect($k('GN 1/1 65mm'))->toBe($k('GN 1/1-65'))
        ->and($k('GN 1/1 65mm'))->toBe($k('gn 1/1 65 mm'))
        ->and($k('GN 1/1 65mm'))->not->toBe($k('GN 1/1 100mm'))
        ->and($k('GN 1/2 65mm'))->not->toBe($k('GN 1/1 65mm'));
});
