<?php

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Services\Knowledge\RezeptAchsen;
use Platform\FoodAlchemist\Tests\Support\SeedsTeamHierarchy;
use Platform\FoodAlchemist\Tests\TestCase;
use Symfony\Component\Uid\UuidV7;

uses(TestCase::class, SeedsTeamHierarchy::class);

/**
 * Spec 52 Runde C — die Achsen eines BESTEHENDEN Rezepts.
 *
 * Beim Erzeugen entscheidet die KI erst, was entsteht; Gang und Warengruppe gibt es da noch
 * nicht. Bei Anreicherung, Ueberarbeitung und Pruefung liegen sie vor — und genau dort blieb
 * das Nachschlagen bisher blind, weil niemand die Achsen mitgab.
 */
beforeEach(function () {
    $this->seedTeamHierarchy();
    $this->actingAs($this->makeUser($this->rootTeam));

    // ⚠ Die Stammdaten sind im Harness leer; ohne Saeen waere das Vokabular inaktiv und
    // der Test wuerde die Filterung gar nicht pruefen (s. WissenAchsenVokabularTest).
    $uuid = fn () => (string) UuidV7::generate();
    DB::table('foodalchemist_dish_main_groups')->insert([
        'uuid' => $uuid(), 'code' => 'HG', 'label' => 'Hauptgang', 'is_inactive' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([['04', '04 Fleisch'], ['01', '01 Gemuese']] as [$c, $n]) {
        DB::table('foodalchemist_lookup_commodity_groups')->insert([
            'uuid' => $uuid(), 'code' => $c, 'name' => $n, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $this->rezept = FoodAlchemistRecipe::create([
        'team_id' => $this->rootTeam->id, 'recipe_key' => 'ax-1', 'name' => 'Testgericht',
        'status' => 'draft', 'is_sales_recipe' => true,
    ]);

    // Die Fixture-Helfer aus dem Seed-Trait nutzen statt eigener Inserts — sonst rate ich
    // Pflichtfelder und teste am Ende meine Fixture statt den Code.
    $this->position = 0;
    $this->mitGp = function (string $code) {
        $gp = $this->makeGp($this->rootTeam, 'GP '.$code);
        $gp->update(['commodity_group_code' => $code]);
        $this->makeIngredient($this->rezept, 'Zutat '.$code, $gp, '100', ++$this->position);
    };
});

it('leitet nichts ab, wenn das Rezept nichts hergibt', function () {
    expect(RezeptAchsen::fuer($this->rezept->fresh()))->toBe([]);
});

it('leitet den Gang aus der Speisen-Hauptgruppe ab', function () {
    $id = DB::table('foodalchemist_dish_main_groups')->where('code', 'HG')->value('id');
    $this->rezept->update(['dish_main_group_id' => $id]);

    expect(RezeptAchsen::fuer($this->rezept->fresh()))->toHaveKey('gang')
        ->and(RezeptAchsen::fuer($this->rezept->fresh())['gang'])->toBe('hg');
});

it('sammelt MEHRERE Warengruppen — ein Gericht fuehrt Fleisch UND Gemuese', function () {
    ($this->mitGp)('04');
    ($this->mitGp)('01');

    expect(RezeptAchsen::fuer($this->rezept->fresh())['warengruppe'])->toEqualCanonicalizing(['04', '01']);
});

it('★ vertraegt die verschmutzten Codes der Grundprodukte', function () {
    // Auf demo stehen neben `01` auch `01 Gemuese&Blattsalat` und `01 Gemüse & Blattsalat`.
    // Ohne das erste Token waere die Haelfte der Zutaten stumm.
    ($this->mitGp)('01 Gemuese&Blattsalat');

    expect(RezeptAchsen::fuer($this->rezept->fresh())['warengruppe'])->toBe(['01']);
});

it('★ laesst einen Code weg, den das Vokabular nicht kennt — statt einen Treffer vorzutaeuschen', function () {
    ($this->mitGp)('99');

    expect(RezeptAchsen::fuer($this->rezept->fresh()))->not->toHaveKey('warengruppe');
});

it('★ leitet die SAISON bewusst NICHT ab', function () {
    // Weder Planung noch Rezept tragen ein Zieldatum (geprueft 2026-09-11). Der heutige
    // Monat waere eine Annahme ueber die ABSICHT: ein Rezept, das im September fuer ein
    // Weihnachtsmenue angereichert wird, bekaeme September-Wissen.
    ($this->mitGp)('04');
    expect(RezeptAchsen::fuer($this->rezept->fresh()))->not->toHaveKey('saison');
});

it('★ leitet die KOMPONENTENROLLE bewusst NICHT ab', function () {
    // Die Rolle beschreibt eine Komponente IM Gericht. Ein ganzes Rezept hat keine.
    ($this->mitGp)('04');
    expect(RezeptAchsen::fuer($this->rezept->fresh()))->not->toHaveKey('komponentenrolle');
});
