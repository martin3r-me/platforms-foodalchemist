<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 93 C · Trend-Automatik je Team: Stufe (aus | bis_geprueft | radar, Standard aus), Schatten-Modus (Standard an: rechnet,
 * schreibt aber keine Trends), Schwellen (null = Startwerte aus der Config), das Trend-KI-Budget (null = kein eigener Deckel)
 * und das Ergebnis des letzten scharfen Laufs (Quelle des Signals „Kandidaten warten“).
 * Rein additiv, down() entfernt nur sie.
 */
return new class extends Migration
{
    private const SPALTEN = ['trend_automatik', 'trend_automatik_schatten', 'trend_automatik_regeln', 'trend_ki_budget_eur', 'trend_automatik_letzter_lauf'];

    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'trend_automatik')) {
                $table->string('trend_automatik', 16)->default('aus');
            }
            if (! Schema::hasColumn('foodalchemist_team_settings', 'trend_automatik_schatten')) {
                $table->boolean('trend_automatik_schatten')->default(true);
            }
            if (! Schema::hasColumn('foodalchemist_team_settings', 'trend_automatik_regeln')) {
                $table->json('trend_automatik_regeln')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_team_settings', 'trend_automatik_letzter_lauf')) {
                $table->json('trend_automatik_letzter_lauf')->nullable();   // Zeit, neue Trends, wartende Kandidaten (Quelle des Signals)
            }
            if (! Schema::hasColumn('foodalchemist_team_settings', 'trend_ki_budget_eur')) {
                $table->decimal('trend_ki_budget_eur', 8, 2)->nullable();
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
