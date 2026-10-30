<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 93 B · Inspirationen bekommen eine Herkunft: manuell (Pinnwand, Bestand bleibt manuell) oder automatisch (Thema aus
 * dem Eingang, erscheint nie auf der Pinnwand). thema_schluessel = normalisierte Schreibweise des Themas für das Bündeln.
 * Rein additiv: neue Spalten, down() entfernt nur sie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_trend_inspirationen', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_trend_inspirationen', 'herkunft')) {
                $table->string('herkunft', 16)->default('manuell');
            }
            if (! Schema::hasColumn('foodalchemist_trend_inspirationen', 'thema_schluessel')) {
                $table->string('thema_schluessel', 190)->nullable();
                $table->index(['team_id', 'herkunft', 'thema_schluessel'], 'fa_trend_insp_thema_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_trend_inspirationen', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_trend_inspirationen', 'thema_schluessel')) {
                $table->dropIndex('fa_trend_insp_thema_idx');
                $table->dropColumn('thema_schluessel');
            }
            if (Schema::hasColumn('foodalchemist_trend_inspirationen', 'herkunft')) {
                $table->dropColumn('herkunft');
            }
        });
    }
};
