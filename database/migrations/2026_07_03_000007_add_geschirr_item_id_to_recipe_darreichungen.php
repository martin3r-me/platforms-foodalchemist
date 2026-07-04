<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Umbau-Spec Darreichungen (Nachtrag 2026-07-03, User-Entscheid): Default-Geschirr
 * je Darreichung — die Form kennt ihr typisches Teil (Schraubglas, kleines Glas,
 * Chafing). Der Concepter schlägt es am Slot vor (Leihpreis/Logistik bleiben
 * Slot-Sache); FA-native Spalte, kein WaWi-Spiegel (Geschirr ist FA-only, #388).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('foodalchemist_recipe_presentations', 'tableware_item_id')) {
            return;
        }
        Schema::table('foodalchemist_recipe_presentations', function (Blueprint $table) {
            $table->foreignId('tableware_item_id')->nullable()
                ->constrained('foodalchemist_tableware_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipe_presentations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tableware_item_id');
        });
    }
};
