<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leitplanken-Vorgabe am Rezept (Dominique 29.10.2026): Eigenleistung (from_scratch | teil_convenience | voll_convenience) und Niveau
 * (haute_cuisine | gehoben | klassisch) — beim Anlegen einmal aus der Planung übernommen, im Editor änderbar. Die Leitplanken-Weiche
 * (selbst oder gekauft) liest danach NUR das Rezept. Bewusst nicht production_depth: das ist das ermittelte Ergebnis, keine Vorgabe.
 * NULL = keine Vorgabe (Standard). Kein Backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_recipes', 'vorgabe_convenience')) {
                $table->string('vorgabe_convenience', 20)->nullable()->comment('from_scratch|teil_convenience|voll_convenience, NULL = keine Vorgabe');
            }
            if (! Schema::hasColumn('foodalchemist_recipes', 'vorgabe_niveau')) {
                $table->string('vorgabe_niveau', 20)->nullable()->comment('haute_cuisine|gehoben|klassisch, NULL = keine Vorgabe');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            foreach (['vorgabe_convenience', 'vorgabe_niveau'] as $spalte) {
                if (Schema::hasColumn('foodalchemist_recipes', $spalte)) {
                    $table->dropColumn($spalte);
                }
            }
        });
    }
};
