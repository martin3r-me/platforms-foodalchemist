<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Das Regenerations-Vokabular kannte nur Hitze — Konvektomat, Induktion, Salamander,
 * Mikrowelle, Bain Marie, Holzkohle-Grill, Pizzaofen, Sous-Vide-Becken. Kein Kühlschrank, keine
 * Tiefkühlung, kein „Raumtemperatur". Jeder Kälte-Schritt landete deshalb in der Lücke „kein
 * Gerät", die die Oberfläche als „kalt servieren" liest.
 *
 * Gefunden an Gericht 2717: dort steht ein vollständiger Dessert-Serviceplan — Carpaccio bei
 * 4 °C auftauen, Sorbet bei −12 °C antemperieren, Knusper auf 20 °C bringen. Sechs Zeilen, alle
 * ohne Gerät, weil es keins gab. Mein erster Reflex war ein Riegel „kein Gerät ⇒ keine Zahlen";
 * der hätte genau diese Arbeit gelöscht. Fachlich ist Auftauen Teil der Regeneration.
 *
 * ⚠ Das Vokabular wird NICHT per Seeder gefüllt, sondern pro Team über ImportSliceCommand — in
 * der Test-Fixture ist die Tabelle deshalb leer. Ein Test, der nur global nach den Slugs sucht,
 * prüft hier nichts. Darum wird die Migrations-Logik gegen ein Team MIT Geräten gefahren.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();

    $this->geraet = function (string $slug, string $name, int $sort) {
        DB::table('foodalchemist_vocab_regeneration_devices')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
            'slug' => $slug, 'name' => $name, 'sort_order' => $sort,
            'is_inactive' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    };

    $this->migration = require __DIR__.'/../../database/migrations/2026_09_13_010000_regeneration_kaelte_stationen.php';
});

it('★ ergaenzt die Kaelte-Stationen fuer ein Team, das bereits Geraete fuehrt', function () {
    ($this->geraet)('konvektomat', 'Konvektomat', 20);

    $this->migration->up();

    $slugs = DB::table('foodalchemist_vocab_regeneration_devices')
        ->where('team_id', $this->rootTeam->id)->where('is_inactive', false)->pluck('slug');

    expect($slugs)->toContain('kuehlung')->toContain('tiefkuehlung')
        ->toContain('raumtemperatur')->toContain('schockfroster');
});

it('die Kaelte-Stationen stehen HINTER den Hitzegeraeten, nicht dazwischen', function () {
    ($this->geraet)('konvektomat', 'Konvektomat', 20);
    $this->migration->up();

    $t = DB::table('foodalchemist_vocab_regeneration_devices')->where('team_id', $this->rootTeam->id);

    expect((int) (clone $t)->whereIn('slug', ['kuehlung', 'tiefkuehlung', 'raumtemperatur', 'schockfroster'])->min('sort_order'))
        ->toBeGreaterThan((int) (clone $t)->where('slug', 'konvektomat')->value('sort_order'));
});

it('★ ein zweiter Lauf legt nichts doppelt an', function () {
    ($this->geraet)('konvektomat', 'Konvektomat', 20);
    $this->migration->up();
    $this->migration->up();

    expect(DB::table('foodalchemist_vocab_regeneration_devices')
        ->where('team_id', $this->rootTeam->id)->where('slug', 'kuehlung')->count())->toBe(1);
});

it('★ ruehrt ein Team ohne Regenerations-Geraete NICHT an — es nutzt das Modul nicht', function () {
    // Ohne Bestand kein Import: sonst bekaeme jedes Team vier Stationen, die es nie bestellt hat.
    $this->migration->up();

    expect(DB::table('foodalchemist_vocab_regeneration_devices')->count())->toBe(0);
});

it('★ der Rueckweg laesst eine Station stehen, an der ein Serviceplan haengt', function () {
    ($this->geraet)('konvektomat', 'Konvektomat', 20);
    $this->migration->up();

    $kuehlung = DB::table('foodalchemist_vocab_regeneration_devices')->where('slug', 'kuehlung')->value('id');
    $rezept = $this->makeRecipe($this->rootTeam, 'Dessert');
    DB::table('foodalchemist_recipe_regenerations')->insert([
        'uuid' => (string) UuidV7::generate(), 'team_id' => $this->rootTeam->id,
        'recipe_id' => $rezept->id, 'component_label' => 'Sorbet (TK)',
        'device_vocab_id' => $kuehlung, 'temp_c' => 4, 'duration_min' => 240,
        'source' => 'manual', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->migration->down();

    // Belegte Station bleibt, die drei unbenutzten verschwinden — sonst risse der Rueckweg
    // einen Serviceplan auseinander.
    expect(DB::table('foodalchemist_vocab_regeneration_devices')->where('slug', 'kuehlung')->exists())->toBeTrue()
        ->and(DB::table('foodalchemist_vocab_regeneration_devices')->where('slug', 'tiefkuehlung')->exists())->toBeFalse();
});
