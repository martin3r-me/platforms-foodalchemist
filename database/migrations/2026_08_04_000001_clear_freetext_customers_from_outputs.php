<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kunden an Ausgabeformen sind ab jetzt CRM-only.
 *
 * Die historischen `customer`-Spalten bleiben als Legacy-Spalten bestehen, damit ältere
 * Deployments/Imports nicht sofort brechen. Inhaltlich zählen sie aber nicht mehr; alte
 * Freitext-Zuordnungen werden bewusst entfernt.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['foodalchemist_foodbooks', 'foodalchemist_menu_cards', 'foodalchemist_menu_plans'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'customer')) {
                DB::table($table)->update(['customer' => null]);
            }
        }
    }

    public function down(): void
    {
        // Freitext-Kunden lassen sich nach dem Entfernen nicht verlustfrei rekonstruieren.
    }
};
