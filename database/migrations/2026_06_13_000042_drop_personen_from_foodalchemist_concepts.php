<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M10c-A (Dominique 2026-06-13): Pax raus aus dem Concept — ein Concept ist
 * person-UNABHÄNGIG (intrinsischer €/Person-Wert; dieselbe Vorlage wird an
 * viele Kunden mit verschiedenen Gästezahlen verkauft). Die bindende Pax-Zahl
 * lebt am Foodbook/Angebot (M11), nicht an der Vorlage. Revidiert M10p-1.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('foodalchemist_concepts', 'personen')) {
            Schema::table('foodalchemist_concepts', function (Blueprint $table) {
                $table->dropColumn('personen');
            });
        }
    }

    public function down(): void
    {
        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            $table->unsignedInteger('personen')->nullable()->after('level');
        });
    }
};
