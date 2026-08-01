<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 31 / Stufe C (GV-Ausbau): Teilnehmerzahl-Default + Wareneinsatz-Budget-Ziel am
 * Speiseplan-Kopf. `default_pax` speist die Produktions-Übergabe (Einträge × Pax → Ziele);
 * `budget_wareneinsatz` = €/Person EK-Zielwert für die Rail-Ampel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_menu_plans', function (Blueprint $table) {
            $table->unsignedInteger('default_pax')->default(100)->after('status');
            $table->decimal('budget_wareneinsatz', 8, 2)->nullable()->after('default_pax');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_menu_plans', function (Blueprint $table) {
            $table->dropColumn(['default_pax', 'budget_wareneinsatz']);
        });
    }
};
