<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 94 · Bilder für die Zeitfenster-Karten Tag, Abend und Tag und Abend (Dominique 08.11.2026), Muster wie das Bild am
 * Konfigurator-Konzept-Eintrag (bild_path + bild_context_file_id). Additiv, nullable.
 */
return new class extends Migration
{
    private const SPALTEN = ['bild_lunch_path', 'bild_lunch_context_file_id', 'bild_dinner_path', 'bild_dinner_context_file_id',
        'bild_day_night_path', 'bild_day_night_context_file_id'];

    public function up(): void
    {
        Schema::table('foodalchemist_konfiguratoren', function (Blueprint $table) {
            foreach (['lunch', 'dinner', 'day_night'] as $t) {
                if (! Schema::hasColumn('foodalchemist_konfiguratoren', "bild_{$t}_path")) {
                    $table->string("bild_{$t}_path")->nullable();
                }
                if (! Schema::hasColumn('foodalchemist_konfiguratoren', "bild_{$t}_context_file_id")) {
                    $table->unsignedBigInteger("bild_{$t}_context_file_id")->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        $da = array_values(array_filter(self::SPALTEN, fn ($s) => Schema::hasColumn('foodalchemist_konfiguratoren', $s)));
        if ($da !== []) {
            Schema::table('foodalchemist_konfiguratoren', fn (Blueprint $table) => $table->dropColumn($da));
        }
    }
};
