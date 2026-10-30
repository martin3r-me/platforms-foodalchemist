<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 93 B · Erschließung am Eingang: thema_inspiration_id (das automatische Thema; inspiration_id bleibt die Pinnwand-
 * Inspiration aus „Auf die Pinnwand“ — beides kann gleichzeitig gelten), passt_zu_trend_id (nur aus der mitgegebenen Trendliste, für Abschnitt C),
 * thema_quelle (ki | manuell — ein von Hand gesetztes Thema fasst die KI nie wieder an), erschlossen_at.
 * Rein additiv: neue Spalten, down() entfernt nur sie.
 */
return new class extends Migration
{
    private const SPALTEN = ['thema_inspiration_id', 'passt_zu_trend_id', 'thema_quelle', 'erschlossen_at'];

    public function up(): void
    {
        Schema::table('foodalchemist_trend_eingang', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_trend_eingang', 'thema_inspiration_id')) {
                $table->unsignedBigInteger('thema_inspiration_id')->nullable()->index();
            }
            if (! Schema::hasColumn('foodalchemist_trend_eingang', 'passt_zu_trend_id')) {
                $table->unsignedBigInteger('passt_zu_trend_id')->nullable()->index();
            }
            if (! Schema::hasColumn('foodalchemist_trend_eingang', 'thema_quelle')) {
                $table->string('thema_quelle', 8)->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_trend_eingang', 'erschlossen_at')) {
                $table->timestamp('erschlossen_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_trend_eingang', function (Blueprint $table) {
            foreach (['thema_inspiration_id', 'passt_zu_trend_id'] as $spalte) {
                if (Schema::hasColumn('foodalchemist_trend_eingang', $spalte)) {
                    $table->dropIndex([$spalte]);
                }
            }
            foreach (self::SPALTEN as $spalte) {
                if (Schema::hasColumn('foodalchemist_trend_eingang', $spalte)) {
                    $table->dropColumn($spalte);
                }
            }
        });
    }
};
