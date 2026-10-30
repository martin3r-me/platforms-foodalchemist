<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 93 C · Trends bekommen eine Herkunft (manuell = Bestand und von Menschen angelegt, automatisch = von der Automatik),
 * einen Merker je Feld, das ein Mensch gesetzt hat (die Automatik fasst es nie wieder an), und die letzten Ereignisse der
 * Automatik (neuer Hype/Trend, Hype wird Trend, aufs Radar) als Quelle der Signale. Rein additiv, down() entfernt nur sie.
 */
return new class extends Migration
{
    private const SPALTEN = ['herkunft', 'manuell_felder', 'automatik_ereignisse'];

    public function up(): void
    {
        Schema::table('foodalchemist_trends', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_trends', 'herkunft')) {
                $table->string('herkunft', 16)->default('manuell');
            }
            if (! Schema::hasColumn('foodalchemist_trends', 'manuell_felder')) {
                $table->json('manuell_felder')->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_trends', 'automatik_ereignisse')) {
                $table->json('automatik_ereignisse')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_trends', function (Blueprint $table) {
            foreach (self::SPALTEN as $spalte) {
                if (Schema::hasColumn('foodalchemist_trends', $spalte)) {
                    $table->dropColumn($spalte);
                }
            }
        });
    }
};
