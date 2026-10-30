<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 93 A · Team-Standards für neue Trend-Quellen: Rhythmus (Standard „aus“, bis Dominique einschaltet) und die
 * Stichwort-Listen, die greifen, wenn eine Quelle keine eigenen hat (leer, bis Dominique sie füllt).
 * Rein additiv: neue Spalten, down() entfernt nur sie.
 */
return new class extends Migration
{
    private const SPALTEN = ['trend_quellen_rhythmus', 'trend_stichworte_ein', 'trend_stichworte_aus'];

    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'trend_quellen_rhythmus')) {
                $table->string('trend_quellen_rhythmus', 16)->default('aus');
            }
            if (! Schema::hasColumn('foodalchemist_team_settings', 'trend_stichworte_ein')) {
                $table->json('trend_stichworte_ein')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_team_settings', 'trend_stichworte_aus')) {
                $table->json('trend_stichworte_aus')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            foreach (self::SPALTEN as $spalte) {
                if (Schema::hasColumn('foodalchemist_team_settings', $spalte)) {
                    $table->dropColumn($spalte);
                }
            }
        });
    }
};
