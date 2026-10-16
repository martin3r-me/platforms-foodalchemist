<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gefäß an der Darreichung (Dominique 2026-10-16): das Gericht ist neutral, die Darreichung definiert Menge UND Gefäß (mehrere Füllmengen = mehrere
 * Darreichungen, z. B. Schraubglas 140 ml und 212 ml). `recipe_presentations.vessel_gp_id` zeigt auf ein Non-Food-GP (WG 16, Geschirr & Ausstattung) und
 * ersetzt `tableware_item_id` (bleibt bis PR D stehen). Der Servier-Typ ist eine Eigenschaft des Gefäßes und steht EINMAL am GP
 * (`gps.serving_vehicle_vocab_id`); `recipe_presentations.serving_vehicle_vocab_id` bedeutet ab jetzt nur noch eine ausdrückliche Übersteuerung.
 *
 * Auf SQLite keine FK-Constraints: ein FK-Nachtrag baut die Tabelle neu und zerstört dort die partiellen Indizes (Ein-Standard-Index der Darreichungen).
 */
return new class extends Migration
{
    public function up(): void
    {
        $mitFk = DB::connection()->getDriverName() !== 'sqlite';

        if (! Schema::hasColumn('foodalchemist_recipe_presentations', 'vessel_gp_id')) {
            Schema::table('foodalchemist_recipe_presentations', function (Blueprint $table) use ($mitFk) {
                $mitFk
                    ? $table->foreignId('vessel_gp_id')->nullable()->constrained('foodalchemist_gps')->nullOnDelete()
                    : $table->unsignedBigInteger('vessel_gp_id')->nullable()->index();
            });
        }
        if (! Schema::hasColumn('foodalchemist_gps', 'serving_vehicle_vocab_id')) {
            Schema::table('foodalchemist_gps', function (Blueprint $table) use ($mitFk) {
                $mitFk
                    ? $table->foreignId('serving_vehicle_vocab_id')->nullable()->constrained('foodalchemist_vocab_serving_vehicles')->nullOnDelete()
                    : $table->unsignedBigInteger('serving_vehicle_vocab_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_gps', 'serving_vehicle_vocab_id')) {
            Schema::table('foodalchemist_gps', function (Blueprint $table) {
                if (DB::connection()->getDriverName() !== 'sqlite') {
                    $table->dropConstrainedForeignId('serving_vehicle_vocab_id');
                } else {
                    $table->dropColumn('serving_vehicle_vocab_id');
                }
            });
        }
        if (Schema::hasColumn('foodalchemist_recipe_presentations', 'vessel_gp_id')) {
            Schema::table('foodalchemist_recipe_presentations', function (Blueprint $table) {
                if (DB::connection()->getDriverName() !== 'sqlite') {
                    $table->dropConstrainedForeignId('vessel_gp_id');
                } else {
                    $table->dropColumn('vessel_gp_id');
                }
            });
        }
    }
};
