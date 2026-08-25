<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Format C1 (Dominique 2026-08-25): Concept-Wordings im Format-Editor format-LOKAL überschreiben —
 * wie im Foodbook (foodbook_blocks.payload_json['wording_overrides']). `payload_json` am
 * format_slot trägt die Per-Gericht-Overrides (Map concept-slot-ID → Anzeigename); das referenzierte
 * Concept bleibt unangetastet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_format_slots')) {
            return;
        }

        Schema::table('foodalchemist_format_slots', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_format_slots', 'payload_json')) {
                $table->json('payload_json')->nullable()->after('text_content');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_format_slots')) {
            return;
        }

        Schema::table('foodalchemist_format_slots', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_format_slots', 'payload_json')) {
                $table->dropColumn('payload_json');
            }
        });
    }
};
