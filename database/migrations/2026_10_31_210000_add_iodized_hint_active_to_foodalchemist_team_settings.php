<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mandanten-Schalter „mit Jodsalz hergestellt“ (15.10.2026): freiwilliger Hinweis auf Speisekarte und Etikett. Org-vererbt
 * (TeamSettingsService::ORG_VERERBT): das Mandanten-Oberteam setzt, Unter-Teams erben, NULL = erben; Code-Standard AUS.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_team_settings', 'iodized_hint_active')) {
            Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
                $table->boolean('iodized_hint_active')->nullable()->after('ai_active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_team_settings', 'iodized_hint_active')) {
            Schema::table('foodalchemist_team_settings', fn (Blueprint $table) => $table->dropColumn('iodized_hint_active'));
        }
    }
};
