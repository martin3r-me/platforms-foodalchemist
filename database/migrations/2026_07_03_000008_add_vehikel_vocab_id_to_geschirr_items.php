<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Umbau-Spec 5b/A2 (Dominique-Befund 2026-07-03): Vehikel↔Geschirr-Mapping —
 * GP↔LA-Muster auf der Equipment-Achse. Servier-Vehikel = abstrakte
 * Präsentationsform (WaWi-Vokabular), Geschirr-Artikel = konkretes Mietteil
 * mit Leihpreis. Der Typ ordnet den Katalog und lässt den Concepter-Picker
 * passende Teile zur aufgelösten Darreichung bevorzugen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('foodalchemist_tableware_items', 'vehicle_vocab_id')) {
            return;
        }
        Schema::table('foodalchemist_tableware_items', function (Blueprint $table) {
            $table->foreignId('vehicle_vocab_id')->nullable()
                ->constrained('foodalchemist_vocab_serving_vehicles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_tableware_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vehicle_vocab_id');
        });
    }
};
