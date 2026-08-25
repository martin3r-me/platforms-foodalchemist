<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foodbook #2 (Dominique 2026-08-25): Schreibstil PRO KAPITEL im Foodbook.
 * Der Standard kommt aus dem Concept/Paket (definiert die Speisen) und fließt live ins
 * Foodbook. Pro Kapitel kann der Stil ÜBERSCHRIEBEN werden — dann werden die Concept-Wordings
 * dieses Kapitels im gewählten Stil neu betextet und foodbook-LOKAL als Block-Override
 * (payload_json['wording_overrides']) gespeichert (Snapshot; das Concept bleibt unangetastet).
 *
 * `writing_style_id` = NULL → Kapitel erbt den Standard aus den Concepten (kein Override).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_foodbook_chapters')) {
            return;
        }

        Schema::table('foodalchemist_foodbook_chapters', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_foodbook_chapters', 'writing_style_id')) {
                $table->foreignId('writing_style_id')->nullable()->after('serving_form_id')
                    ->constrained('foodalchemist_writing_styles')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_foodbook_chapters')) {
            return;
        }

        Schema::table('foodalchemist_foodbook_chapters', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_foodbook_chapters', 'writing_style_id')) {
                $table->dropConstrainedForeignId('writing_style_id');
            }
        });
    }
};
