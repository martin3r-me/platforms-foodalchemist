<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wording-Kette (UX-Umbau 2026-07-03): expliziter Anzeigename-Override am
 * Foodbook-Block — oberste Stufe der Kette
 * Foodbook-Block.wording → Concept-Slot.wording → Gericht.vk_wording_standard → Name.
 * `kundentext` verliert seine Doppelrolle als Label und wird wieder reiner
 * Beschreibungs-/Untertiteltext (bleibt als Legacy-Fallback in der Auflösung).
 * Additiv, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_foodbook_blocks', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_foodbook_blocks', 'wording')) {
                $table->text('wording')->nullable()->after('bezeichnung');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_foodbook_blocks', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_foodbook_blocks', 'wording')) {
                $table->dropColumn('wording');
            }
        });
    }
};
