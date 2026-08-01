<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 31 / Stufe C (GV-Ausbau): optionaler Pax-Override je Eintrag (Zelle). NULL = es gilt
 * der Plan-Default (`menu_plans.default_pax`). Damit produziert die »Woche → Produktion«-
 * Übergabe mit realen Kopfzahlen (Menü 1: 120, Vegetarisch: 30 …).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_menu_plan_entries', function (Blueprint $table) {
            $table->unsignedInteger('pax')->nullable()->after('line_id');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_menu_plan_entries', function (Blueprint $table) {
            $table->dropColumn('pax');
        });
    }
};
