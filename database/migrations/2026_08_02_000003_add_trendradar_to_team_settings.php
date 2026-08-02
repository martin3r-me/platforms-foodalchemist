<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trendradar: pro-Team-Steuerung der 08:00-Automatisierung + Signal, damit sie im UI
 * (Einstellungen → Trendradar) statt per ENV geschaltet wird. Alle nullable → Code-Default
 * (TeamSettingsService): auto AUS, Signal AN, Limit aus Config. Team-lokal (nicht org-vererbt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            $table->boolean('trend_auto_enabled')->nullable()->comment('08:00-Konzept-Automatisierung für dieses Team');
            $table->integer('trend_auto_limit')->nullable()->comment('Anzahl Top-Trends je Lauf; NULL = Config-Default');
            $table->boolean('trend_signal_enabled')->nullable()->comment('Vorschlag als Signal in die Inbox legen');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            $table->dropColumn(['trend_auto_enabled', 'trend_auto_limit', 'trend_signal_enabled']);
        });
    }
};
