<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die beiden fehlenden Anleitungs-Ebenen am eingefrorenen Auftrag (Regelwerk
 * Verkaufsgerichte §3, Nachzug zu 2026_09_04_000001).
 *
 * Ein gestarteter Produktionsauftrag friert seinen Stand ein — bisher aber nur die
 * PRODUKTIONS-Schritte (`steps_snapshot`, Spec 27). Zwei Ebenen fehlten:
 *
 *  - `regen_snapshot`: das Regenerations-Programm je Komponente (V-19). Auftrag und
 *    Wandmonitor lasen es bis hierher aus `darreichung` — den Regenerations-Skalaren der
 *    Standard-Darreichung, die KEIN Schreibpfad füllt (`SalesRecipeService::updateVk`
 *    spiegelt Behälter und Vehikel, die Regenerations-Werte nicht). Am Pass stand die
 *    Regeneration deshalb leer, obwohl sie im Editor gepflegt ist.
 *  - `plating_snapshot`: die Anrichte-Schritte samt Fotos. Ohne sie sieht der Pass den
 *    Teller-Aufbau nur als Spiegel-Text, also ohne Bilder — gerade das, wofür die Ebene
 *    gebaut wurde.
 *
 * Beide sind JSON und NULL-bar: NULL = Alt-Auftrag, der auf die bisherigen Fallbacks
 * zurückfällt (Darreichungs-Skalare bzw. `plating_text`). Kein Backfill: ein bereits
 * gestarteter Auftrag darf seinen eingefrorenen Stand nicht nachträglich ändern.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_production_order_lines')) {
            return;
        }

        Schema::table('foodalchemist_production_order_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_production_order_lines', 'regen_snapshot')) {
                $table->json('regen_snapshot')->nullable()->after('steps_snapshot')
                    ->comment('V-19-Regenerationsprogramm je Komponente, eingefroren (NULL = Alt-Auftrag)');
            }
            if (! Schema::hasColumn('foodalchemist_production_order_lines', 'plating_snapshot')) {
                $table->json('plating_snapshot')->nullable()->after('regen_snapshot')
                    ->comment('Anrichte-Schritte inkl. Fotos, eingefroren (NULL = Alt-Auftrag)');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_production_order_lines')) {
            return;
        }

        Schema::table('foodalchemist_production_order_lines', function (Blueprint $table) {
            foreach (['regen_snapshot', 'plating_snapshot'] as $spalte) {
                if (Schema::hasColumn('foodalchemist_production_order_lines', $spalte)) {
                    $table->dropColumn($spalte);
                }
            }
        });
    }
};
