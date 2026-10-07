<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Speiseplan-Eintrag kann optional eine konkrete Darreichung seines Gerichts tragen
 * (wie Konzept-Slot / Paket-Gericht). Auflösung im DarreichungResolver:
 * explizite Darreichung → Standard-Darreichung des Gerichts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('foodalchemist_menu_plan_entries', 'presentation_id')) {
            return;
        }
        Schema::table('foodalchemist_menu_plan_entries', function (Blueprint $table) {
            $table->foreignId('presentation_id')->nullable()->after('sales_recipe_id')
                ->constrained('foodalchemist_recipe_presentations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('foodalchemist_menu_plan_entries', 'presentation_id')) {
            return;
        }
        Schema::table('foodalchemist_menu_plan_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('presentation_id');
        });
    }
};
