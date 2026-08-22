<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Format-Modul (Phase A): Concept → Format (Ownership-FK). Eine Zusammenstellung
 * gehört als Edition zu höchstens EINEM Format; freistehende Concepts bleiben
 * erlaubt (nullable). Format löschen → Editionen werden wieder freistehend
 * (nullOnDelete). `format_position` ordnet die Editionen innerhalb des Formats.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_concepts')) {
            return;
        }

        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_concepts', 'format_id')) {
                $table->foreignId('format_id')->nullable()->after('template_source_id')
                    ->constrained('foodalchemist_formats')->nullOnDelete();
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'format_position')) {
                $table->integer('format_position')->default(0)->after('format_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_concepts')) {
            return;
        }

        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_concepts', 'format_id')) {
                $table->dropConstrainedForeignId('format_id');
            }
            if (Schema::hasColumn('foodalchemist_concepts', 'format_position')) {
                $table->dropColumn('format_position');
            }
        });
    }
};
