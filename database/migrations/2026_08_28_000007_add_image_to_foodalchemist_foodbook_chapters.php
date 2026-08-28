<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 43 (Bild-Epic) — optionales Kapitel-Bild. Überschreibt in der Präsentation das
 * Concept-Titelbild (Kapitel-Band-Reihenfolge: Kapitel-Bild › Concept-Titelbild).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_foodbook_chapters')) {
            return;
        }
        Schema::table('foodalchemist_foodbook_chapters', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_foodbook_chapters', 'image_context_file_id')) {
                $table->unsignedBigInteger('image_context_file_id')->nullable()->after('description');
            }
            if (! Schema::hasColumn('foodalchemist_foodbook_chapters', 'image_path')) {
                $table->string('image_path')->nullable()->after('image_context_file_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_foodbook_chapters')) {
            return;
        }
        Schema::table('foodalchemist_foodbook_chapters', function (Blueprint $table) {
            foreach (['image_context_file_id', 'image_path'] as $col) {
                if (Schema::hasColumn('foodalchemist_foodbook_chapters', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
