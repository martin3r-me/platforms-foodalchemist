<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 85 Controlling PR-B (Dominique 19.10.): „Standorte übernehmen unsere Leitartikel“ — Einstellung am Kunden-Hauptteam.
 * NULL = nie gesetzt = AN (Standard). An: Pin des Standorts > Pin des Hauptteams > Plattform-Lead; aus: nur eigener Pin.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_team_settings', 'standorte_uebernehmen_leitartikel')) {
            Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
                $table->boolean('standorte_uebernehmen_leitartikel')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_team_settings', 'standorte_uebernehmen_leitartikel')) {
            Schema::table('foodalchemist_team_settings', fn (Blueprint $table) => $table->dropColumn('standorte_uebernehmen_leitartikel'));
        }
    }
};
