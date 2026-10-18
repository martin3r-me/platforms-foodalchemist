<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grundeinstellungen-Paket (Plan B, Kurator 18.10.2026, F5): Aufschlagsklassen werden ohne BHG-Werte kopiert — raw_markup_pct, service_pct und
 * profit_pct sind dort „keine Angabe“ (NULL), nicht 0 (0 hieße VK = EK). Der Standard 0 bleibt für die übrigen Anlagepfade; nur NULL wird erlaubt.
 * MargeService::vkVorschlag liefert bei NULL keinen Preis. Rückweg: NULL → 0, dann wieder NOT NULL.
 */
return new class extends Migration
{
    private const SPALTEN = ['raw_markup_pct', 'service_pct', 'profit_pct'];

    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_markup_classes')) {
            return;
        }
        Schema::table('foodalchemist_markup_classes', function (Blueprint $t) {
            foreach (self::SPALTEN as $s) {
                $t->decimal($s, 7, 2)->nullable()->default(0)->change();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_markup_classes')) {
            return;
        }
        foreach (self::SPALTEN as $s) {
            \Illuminate\Support\Facades\DB::table('foodalchemist_markup_classes')->whereNull($s)->update([$s => 0]);
        }
        Schema::table('foodalchemist_markup_classes', function (Blueprint $t) {
            foreach (self::SPALTEN as $s) {
                $t->decimal($s, 7, 2)->default(0)->change();
            }
        });
    }
};
