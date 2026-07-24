<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 19 „Foodbook-Leitstelle A–Z" — M2: Dimension-Defaults am Foodbook.
 *
 * Das Foodbook trägt die frame-weiten Dimensions-Defaults, die Kapitel + Vorschläge +
 * KI-Erstellung erben (Kaskade Kapitel(+Eltern) → Foodbook → Segment, aufgelöst in
 * `leitplanken()`/`kapitelZiele()`, E3.4/E4.2):
 *   - default_event_type_id / default_serving_form_id → Eventtyp/Servierform-Default
 *   - target_food_cost_pct     → Ziel-Wareneinsatz % (SOLL der WE-Ampel, E4.4)
 *   - food_cost_tolerance_pp   → Toleranz-Band in Prozentpunkten (Code-Default 5.0, wenn NULL)
 * + Pivot `foodbook_service_moments` (Tagesablauf 1–n; Einsatzmomente des ganzen Foodbooks).
 *
 * Additiv, rückwärtskompatibel (alle Spalten NULL / Pivot leer ⇒ heutiges Verhalten).
 * ALTER auf Bestandstabelle mit `unsignedBigInteger + index` statt FK (Live-Daten, Spec-Regel);
 * neuer Pivot nutzt constrained FK (analog M1).
 *
 * Namens-Hinweis: Spec-Shorthand `foodbook_service_moments` → präfigiert
 * `foodalchemist_foodbook_service_moments` (Modul-Konvention, vgl. M1/concept_service_moments).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'default_event_type_id')) {
                $table->unsignedBigInteger('default_event_type_id')->nullable()->index();
            }
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'default_serving_form_id')) {
                $table->unsignedBigInteger('default_serving_form_id')->nullable()->index();
            }
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'target_food_cost_pct')) {
                $table->decimal('target_food_cost_pct', 5, 2)->nullable();   // Ziel-WE %; null → Kaskade/zielWareneinsatzPct()
            }
            if (! Schema::hasColumn('foodalchemist_foodbooks', 'food_cost_tolerance_pp')) {
                $table->decimal('food_cost_tolerance_pp', 5, 2)->nullable(); // Toleranz in PP; null → Code-Default 5.0
            }
        });

        // Pivot Foodbook-Einsatzmomente (Tagesablauf 1–n).
        if (! Schema::hasTable('foodalchemist_foodbook_service_moments')) {
            Schema::create('foodalchemist_foodbook_service_moments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('foodbook_id')->constrained('foodalchemist_foodbooks')->cascadeOnDelete();
                $table->foreignId('service_moment_id')->constrained('foodalchemist_service_moments')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['foodbook_id', 'service_moment_id'], 'fa_foodbook_service_moments_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_foodbook_service_moments');

        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            foreach (['default_event_type_id', 'default_serving_form_id', 'target_food_cost_pct', 'food_cost_tolerance_pp'] as $col) {
                if (Schema::hasColumn('foodalchemist_foodbooks', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
