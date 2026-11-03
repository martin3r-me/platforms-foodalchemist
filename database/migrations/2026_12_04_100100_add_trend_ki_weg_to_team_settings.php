<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 93 · Weg der Trend-KI je Team: plattform (Standard, Plattform-Provider) | extern (jeder Erschließen-/Ableitungs-Lauf legt
 * einen KI-Auftrag an, beantwortet per MCP — keine Plattform-Kosten). Rein additiv, down() entfernt nur sie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'trend_ki_weg')) {
                $table->string('trend_ki_weg', 16)->default('plattform');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_team_settings', 'trend_ki_weg')) {
                $table->dropColumn('trend_ki_weg');
            }
        });
    }
};
