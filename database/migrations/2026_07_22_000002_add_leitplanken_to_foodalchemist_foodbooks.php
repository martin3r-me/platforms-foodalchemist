<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kreative Leitplanken pro Foodbook: eine Guideline, die bei Gericht-/Rezeptur-Erstellung
 * berücksichtigt wird. `kundentyp` (Enterprise: für wen — Kette/Gruppe/Einzelkunde/…),
 * `default_niveau` + `default_convenience` = Default-Vorgabe (aus dem Küchen-Segment
 * vorbelegt, pro Foodbook überschreibbar). Das echte Niveau je Stufe (basic/hochwertig/
 * premium) lebt pro Kapitel/Konzept (concept.level) — das hier ist der Foodbook-Default,
 * den Kapitel + Vorschläge + KI-Erstellung erben. Additiv, rückwärtskompatibel (alle NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'kundentyp')) {
                $table->string('kundentyp')->nullable();            // Enterprise-Kundentyp (TeamSettingsService::KUNDENTYPEN)
            }
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'default_niveau')) {
                $table->string('default_niveau')->nullable();       // klassisch|gehoben|haute_cuisine; null → Segment-Default
            }
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'default_convenience')) {
                $table->string('default_convenience')->nullable();  // from_scratch|teil_convenience|voll_convenience; null → Segment-Default
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            foreach (['kundentyp', 'default_niveau', 'default_convenience'] as $col) {
                if (Schema::hasColumn('foodalchemist_foodbooks', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
