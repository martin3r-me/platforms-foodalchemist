<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 32 · C4 — Schwelle für „Wareneinsatz Ist ≠ Rezeptur".
 *
 * In Prozentpunkten vom Umsatz, nicht in Euro: eine Abweichung von 2.000 € ist bei 50.000 €
 * Monatsumsatz ein Alarm und bei 900.000 € Rauschen. Default 3 pp — großzügig genug, dass
 * normaler Verschnitt nicht jede Nacht ein Signal erzeugt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_team_settings')
            || Schema::hasColumn('foodalchemist_team_settings', 'we_deviation_threshold_pp')) {
            return;
        }

        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            $table->decimal('we_deviation_threshold_pp', 5, 2)->nullable()
                ->comment('Spec 32 C4: ab wie vielen Prozentpunkten Abweichung (Ist-Einkauf vs. theoretischer Wareneinsatz, bezogen auf den Umsatz) ein Signal feuert');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_team_settings', 'we_deviation_threshold_pp')) {
            Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
                $table->dropColumn('we_deviation_threshold_pp');
            });
        }
    }
};
