<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Format-Modul (Phase C): Format-Kapitel im Foodbook. Ein Kapitel mit gesetztem
 * `format_id` ist ein LIVE-Kapitel — es rendert Identität + Editionen live aus dem
 * Format (statt manueller Blöcke). Diskriminator = `format_id IS NOT NULL`.
 * nullOnDelete: Format gelöscht → Kapitel bleibt als (leerer) Platzhalter, der
 * Send-Snapshot bewahrt bereits versendete Dokumente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_foodbook_chapters')
            || Schema::hasColumn('foodalchemist_foodbook_chapters', 'format_id')) {
            return;
        }

        Schema::table('foodalchemist_foodbook_chapters', function (Blueprint $table) {
            $table->foreignId('format_id')->nullable()->after('parent_id')
                ->constrained('foodalchemist_formats')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_foodbook_chapters')
            || ! Schema::hasColumn('foodalchemist_foodbook_chapters', 'format_id')) {
            return;
        }

        Schema::table('foodalchemist_foodbook_chapters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('format_id');
        });
    }
};
