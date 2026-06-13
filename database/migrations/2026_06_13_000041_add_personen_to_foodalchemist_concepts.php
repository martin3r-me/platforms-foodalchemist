<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M10p-1 / Doc 15 §9 (C-08): Personen-/Gästezahl am Concept — Basis für den
 * WaWi-Menü-Teil: Gesamtpreis = Σ Per-Person-Preise × Personen und Mengen-
 * Hochrechnung (Menge/Person × Personen) je Gericht. NULL = nur Per-Person-Sicht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            $table->unsignedInteger('personen')->nullable()->after('niveau');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            $table->dropColumn('personen');
        });
    }
};
