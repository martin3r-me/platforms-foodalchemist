<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 19 „Foodbook-Leitstelle A–Z" — M3: Kapitel-Ziele (SOLL) + Anlage-Stand.
 *
 * Die Ziele wandern vom flachen Slot ans Kapitel (Entscheidung: das Kapitel IST der
 * n-tiefe Baum). Jedes Kapitel trägt seine SOLL-Dimensionen; `strukturAusGeruest`
 * stempelt Slot-Ziele einmalig aufs neue Kapitel (E4.1), `kapitelZiele()` löst die
 * Vererbung Kapitel→Eltern→Slot→Foodbook auf (E4.2).
 *
 * SOLL-Spalten:
 *   - target_count            → Soll-Anzahl Gerichte/Positionen im Kapitel
 *   - price_anchor/min/max     → Preis-Korridor je Position (spiegelt Slot-Felder)
 *   - niveau                   → KANONISCHER Niveau-String; Übergang zu concept.level
 *                                IMMER via TeamSettingsService::denormNiveauFuerConcept
 *   - service_moment_id         → Einsatzmoment-Override (sonst Foodbook-Default)
 *   - serving_form_id           → Servierform-Override (Scharnier DarreichungResolver::fuerBlock, E7.1)
 *   - pricing_mode              → paket|einzel|gemischt (Model-Const, weiche Prüfung)
 *   - target_food_cost_pct      → Ziel-Wareneinsatz % (SOLL der WE-Ampel, E4.4)
 *
 * Anlage-Spalten (Kapitel-Go „Anlegen", E7.3 — NICHT der Versand-Status `status`):
 *   - released_at / released_by (users.id ohne Cross-Modul-FK) / release_note / release_result json
 *
 * `chapters.status` (draft|sent|archived) bleibt der Versand-Status und wird NICHT angefasst.
 *
 * Additiv, rückwärtskompatibel (alle Spalten NULL ⇒ heutiges Verhalten). ALTER auf
 * Bestandstabelle mit `unsignedBigInteger + index` statt FK (Live-Daten, Spec-Regel);
 * idempotent via hasColumn-Guards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_foodbook_chapters', function (Blueprint $table) {
            // --- SOLL-Ziele ---
            if (! Schema::hasColumn('foodalchemist_foodbook_chapters', 'target_count')) {
                $table->integer('target_count')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_foodbook_chapters', 'price_anchor')) {
                $table->decimal('price_anchor', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_foodbook_chapters', 'price_min')) {
                $table->decimal('price_min', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_foodbook_chapters', 'price_max')) {
                $table->decimal('price_max', 10, 2)->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_foodbook_chapters', 'niveau')) {
                $table->string('niveau')->nullable();   // KANONISCH; concept.level nur via denormNiveauFuerConcept
            }
            if (! Schema::hasColumn('foodalchemist_foodbook_chapters', 'service_moment_id')) {
                $table->unsignedBigInteger('service_moment_id')->nullable()->index();
            }
            if (! Schema::hasColumn('foodalchemist_foodbook_chapters', 'serving_form_id')) {
                $table->unsignedBigInteger('serving_form_id')->nullable()->index();
            }
            if (! Schema::hasColumn('foodalchemist_foodbook_chapters', 'pricing_mode')) {
                $table->string('pricing_mode', 12)->nullable();   // paket|einzel|gemischt (Model-Const)
            }
            if (! Schema::hasColumn('foodalchemist_foodbook_chapters', 'target_food_cost_pct')) {
                $table->decimal('target_food_cost_pct', 5, 2)->nullable();
            }

            // --- Anlage-Stand (Kapitel-Go, E7.3) ---
            if (! Schema::hasColumn('foodalchemist_foodbook_chapters', 'released_at')) {
                $table->timestamp('released_at')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_foodbook_chapters', 'released_by')) {
                $table->unsignedBigInteger('released_by')->nullable();   // users.id, KEINE Cross-Modul-FK
            }
            if (! Schema::hasColumn('foodalchemist_foodbook_chapters', 'release_note')) {
                $table->text('release_note')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_foodbook_chapters', 'release_result')) {
                $table->json('release_result')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_foodbook_chapters', function (Blueprint $table) {
            foreach ([
                'target_count', 'price_anchor', 'price_min', 'price_max', 'niveau',
                'service_moment_id', 'serving_form_id', 'pricing_mode', 'target_food_cost_pct',
                'released_at', 'released_by', 'release_note', 'release_result',
            ] as $col) {
                if (Schema::hasColumn('foodalchemist_foodbook_chapters', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
