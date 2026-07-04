<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Umbau-Spec Darreichungen Phase 3: Slots/Paket-Gerichte können optional eine
 * konkrete Darreichung des Gerichts referenzieren. Auflösung (DarreichungResolver):
 * explizite Slot-Darreichung → (Phase 4) Darreichung passend zur Konzept-Servierform
 * → Standard-Darreichung des Gerichts.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['foodalchemist_concept_slots', 'foodalchemist_package_dishes'] as $tableName) {
            if (Schema::hasColumn($tableName, 'presentation_id')) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('presentation_id')->nullable()
                    ->constrained('foodalchemist_recipe_presentations')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['foodalchemist_concept_slots', 'foodalchemist_package_dishes'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'presentation_id')) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('presentation_id');
            });
        }
    }
};
