<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KI-Abrechnung je Mandant (Dominique 23.10.2026): Projekt-Mandanten und Teams ohne Mandanten-Zeile haben einen Monatsdeckel (Standard
 * `foodalchemist.ai.projekt_deckel_eur`). `ki_budget_eur_monat = NULL` heißt dort „Standard“ — „unbegrenzt“ braucht deshalb ein eigenes Feld,
 * das der Master-Admin je Mandant setzt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_team_kontingente') && ! Schema::hasColumn('foodalchemist_team_kontingente', 'ki_deckel_unbegrenzt')) {
            Schema::table('foodalchemist_team_kontingente', function (Blueprint $table) {
                $table->boolean('ki_deckel_unbegrenzt')->default(false)->after('ki_budget_eur_monat');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_team_kontingente', 'ki_deckel_unbegrenzt')) {
            Schema::table('foodalchemist_team_kontingente', function (Blueprint $table) {
                $table->dropColumn('ki_deckel_unbegrenzt');
            });
        }
    }
};
