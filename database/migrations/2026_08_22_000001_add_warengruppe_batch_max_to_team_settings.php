<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warengruppen-Topf-Deckel je Recipe-Hauptgruppe (category.main_group_id) — feinere Fallback-Ebene
 * für die Produktionszeit VOR dem globalen Team-Default. JSON {main_group_id: kg}. Greift nur, wenn
 * WEDER Rezept- noch Posten-Deckel gesetzt ist; leere/fehlende Gruppe = Team-Default. Bewusst nur kg
 * (Stück-Ertrag bleibt auf dem Team-Default). Recipe-Hauptgruppe ist die Basisrezept-Achse —
 * dish_main_group ist VK-only und trägt beim Topf-Deckel (Basisrezept-Pfad) nicht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'warengruppe_batch_max_kg')) {
                $table->json('warengruppe_batch_max_kg')->nullable()
                    ->comment('Topf-Deckel kg je Recipe-Hauptgruppe {main_group_id: kg} — Fallback vor Team-Default. NULL = keiner.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_team_settings', 'warengruppe_batch_max_kg')) {
                $table->dropColumn('warengruppe_batch_max_kg');
            }
        });
    }
};
