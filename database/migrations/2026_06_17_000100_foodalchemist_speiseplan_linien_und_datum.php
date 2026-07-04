<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M14-03 / Speiseplan v2 — Achsen-Umbau auf Kantinen-/Kita-Logik:
 *   • Menü-Linien (Menü 1, Vegetarisch, Vital, Dessert …) als eigene Achse,
 *     pro Speiseplan frei definierbar (Farbe + Veggie-Flag fürs GV-Tagescheck).
 *   • Einträge bekommen ein ECHTES Datum (statt nur abstraktem Wochen-Zyklus) +
 *     die Linien-Zuordnung. Der Zyklus (zyklus_wochen) bleibt als ausrollbare Vorlage.
 *
 * Additiv + idempotent (hasTable/hasColumn-Guards). Engine-agnostisch: neue Tabelle
 * mit FK bei CREATE (ok), an der Bestandstabelle nur nullable Spalten + Index (kein
 * ->after, kein ALTER-add-FK, kein CHECK). Die line_id-FK wird app-seitig gepflegt
 * (Service nullt die Einträge beim Linien-Löschen).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_menu_plan_lines')) {
            Schema::create('foodalchemist_menu_plan_lines', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->foreignId('menu_plan_id')->constrained('foodalchemist_menu_plans')->cascadeOnDelete();
                $table->string('name');
                $table->string('farbe', 16)->nullable();             // Token/Hex für die Zeilen-Markierung
                $table->boolean('ist_vegetarisch')->default(false);  // GV: „Veggie täglich"-Check
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('foodalchemist_menu_plan_entries')) {
            Schema::table('foodalchemist_menu_plan_entries', function (Blueprint $table) {
                if (! Schema::hasColumn('foodalchemist_menu_plan_entries', 'line_id')) {
                    $table->unsignedBigInteger('line_id')->nullable()->index();
                }
                if (! Schema::hasColumn('foodalchemist_menu_plan_entries', 'datum')) {
                    $table->date('datum')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('foodalchemist_menu_plan_entries')) {
            Schema::table('foodalchemist_menu_plan_entries', function (Blueprint $table) {
                foreach (['line_id', 'datum'] as $spalte) {
                    if (Schema::hasColumn('foodalchemist_menu_plan_entries', $spalte)) {
                        $table->dropColumn($spalte);
                    }
                }
            });
        }
        Schema::dropIfExists('foodalchemist_menu_plan_lines');
    }
};
