<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Echte Lead-LA-Referenz auf dem GP (GL-03): wird im Import aus dem Legacy-Wert
 * umverdrahtet, sobald die supplier_items da sind. Die V-27-Kette (Rangliste +
 * team-scoped Sperren/Pins) ersetzt dieses Einzel-Feld in der D-2-Ausbaustufe —
 * bis dahin gilt: ein globaler Lead (GL-03 ⚠D1-Arbeitsannahme).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_gps', function (Blueprint $table) {
            $table->foreignId('lead_la_supplier_item_id')->nullable()
                ->after('lead_la_supplier_item_legacy_id')
                ->constrained('foodalchemist_supplier_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_gps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_la_supplier_item_id');
        });
    }
};
