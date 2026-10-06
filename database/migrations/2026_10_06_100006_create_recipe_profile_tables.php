<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 60 · P5: Basisrezept-Profil — die Brücke vom Anker-Hintergrund zum Basisrezept-Vordergrund.
 *
 * `recipe_profile`        je Rezept: Abdeckung, Eigenschaften, offene Bedarfe, Quell-Hash
 * `recipe_profile_anker`  je Rezept die Kern-Anker mit Anteil am Aromaprofil (Summe 100 %)
 *
 * Materialisiert und abgeleitet: {@see \Platform\FoodAlchemist\Services\Pairing\RezeptProfil}
 * baut neu, sobald sich der Quell-Hash (Zutaten, Mengen, Zuordnungen, Anker-Wissen) ändert.
 * Nie von Hand gepflegt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_recipe_profile', function (Blueprint $t) {
            $t->foreignId('recipe_id')->primary()->constrained('foodalchemist_recipes', 'id', 'fa_rprof_recipe_fk')->cascadeOnDelete();
            $t->decimal('abdeckung', 5, 2)->default(0)->comment('Anteil der Rezeptmasse mit Anker, in %');
            $t->json('eigenschaften')->nullable()->comment('{achse: {stufe, quelle}}');
            $t->json('offene_bedarfe')->nullable()->comment('[{achse, staerke, von}] — innerhalb des Rezepts nicht gedeckt');
            $t->char('quelle_hash', 64);
            $t->timestamps();
        });

        Schema::create('foodalchemist_recipe_profile_anker', function (Blueprint $t) {
            $t->foreignId('recipe_id')->constrained('foodalchemist_recipes', 'id', 'fa_rpa_recipe_fk')->cascadeOnDelete();
            $t->foreignId('anchor_id')->constrained('foodalchemist_vocab_pairing_anchors', 'id', 'fa_rpa_anchor_fk')->cascadeOnDelete();
            $t->decimal('anteil', 5, 2)->comment('Anteil am Aromaprofil in %');
            $t->string('verfahren', 24)->nullable();
            $t->primary(['recipe_id', 'anchor_id']);
            $t->index('anchor_id', 'fa_rpa_anchor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_recipe_profile_anker');
        Schema::dropIfExists('foodalchemist_recipe_profile');
    }
};
