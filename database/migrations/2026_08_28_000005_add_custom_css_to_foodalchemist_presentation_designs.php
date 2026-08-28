<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 43 (Stufe 2 „Leinwand via Code") — ein Präsentations-Design kann KI-geschriebenes,
 * sandboxed CSS tragen (custom_css). CSS-only, sanitisiert (kein `<`/`@import`/`expression`),
 * wirkt auf die eigenständige, chrome-freie Präsentationsseite. Content bleibt datengebunden.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_presentation_designs')) {
            return;
        }
        Schema::table('foodalchemist_presentation_designs', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_presentation_designs', 'custom_css')) {
                $table->longText('custom_css')->nullable()->after('tokens_json');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_presentation_designs')) {
            return;
        }
        Schema::table('foodalchemist_presentation_designs', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_presentation_designs', 'custom_css')) {
                $table->dropColumn('custom_css');
            }
        });
    }
};
