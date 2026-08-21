<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team-weiter Standard-Topf-Deckel (Kessel-Kapazität je Koch-Vorgang) — Fallback für die
 * Produktionszeit-Rechnung, wenn WEDER Rezept noch Posten einen eigenen Deckel pflegen. Löst die
 * Hardcode-Konstante FoodAlchemistRecipe::DEFAULT_BATCH_MAX_* pro Team ab (Konstante bleibt letzter
 * Fallback, wenn auch das Team nichts pflegt). NULL = Code-Default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'default_batch_max_kg')) {
                $table->decimal('default_batch_max_kg', 9, 3)->nullable()
                    ->comment('Standard-Topf-Deckel kg je Koch-Vorgang (Fallback ohne Rezept-/Posten-Deckel). NULL = Code-Default.');
            }
            if (! Schema::hasColumn('foodalchemist_team_settings', 'default_batch_max_pieces')) {
                $table->decimal('default_batch_max_pieces', 9, 2)->nullable()
                    ->comment('Standard-Topf-Deckel Stück je Koch-Vorgang (Fallback). NULL = Code-Default.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            foreach (['default_batch_max_kg', 'default_batch_max_pieces'] as $c) {
                if (Schema::hasColumn('foodalchemist_team_settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
