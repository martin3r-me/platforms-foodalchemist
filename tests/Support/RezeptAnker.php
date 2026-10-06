<?php

namespace Platform\FoodAlchemist\Tests\Support;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeIngredient;
use Platform\FoodAlchemist\Models\FoodAlchemistVocabEinheit;
use Symfony\Component\Uid\UuidV7;

/**
 * Spec 60 · P7c: einem Rezept einen Aroma-Anker geben — so, wie es im Modell entsteht: über eine
 * Zutat, deren Grundprodukt den Anker trägt. Ersetzt das frühere `setRecipeAnker` in Fixtures
 * (KI-Anker am Rezept gibt es nicht mehr; das Aromenprofil leitet sich aus den Zutaten ab).
 */
final class RezeptAnker
{
    private static int $n = 0;

    public static function gib(FoodAlchemistRecipe $rezept, int $ankerId, string $gramm = '100'): FoodAlchemistRecipeIngredient
    {
        self::$n++;
        $name = (string) (DB::table('foodalchemist_vocab_pairing_anchors')->where('id', $ankerId)->value('display_de') ?? 'Anker');
        $gp = FoodAlchemistGp::create([
            'team_id' => $rezept->team_id,
            'gp_key' => 'rezeptanker-'.$ankerId.'-'.self::$n.'|test|test',
            'name' => $name.' (Test '.self::$n.')',
        ]);
        DB::table('foodalchemist_gp_anchor_mappings')->insert([
            'uuid' => (string) UuidV7::generate(), 'team_id' => $rezept->team_id, 'gp_id' => $gp->id,
            'anchor_id' => $ankerId, 'role' => 'kern', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $g = FoodAlchemistVocabEinheit::firstOrCreate(
            ['team_id' => $rezept->team_id, 'slug' => 'g'],
            ['display_de' => 'Gramm', 'dimension' => 'mass', 'default_in_g' => 1],
        );

        $zeile = FoodAlchemistRecipeIngredient::create([
            'team_id' => $rezept->team_id, 'recipe_id' => $rezept->id, 'gp_id' => $gp->id,
            'raw_text' => $gp->name, 'quantity' => $gramm, 'unit_vocab_id' => $g->id,
            'position' => 100 + self::$n,
        ]);
        // wie die Neuberechnung in Produktion: Profil sofort mitbauen
        app(\Platform\FoodAlchemist\Services\Pairing\RezeptProfil::class)->fuer((int) $rezept->id);

        return $zeile;
    }
}
