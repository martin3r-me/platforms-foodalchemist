<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 43 (Bild-Epic) — Konzept-Titelbild als Grundlage. Ein Concept kann ein Bild tragen
 * (context_file + Legacy-Pfad); es dient als Basis für Kapitel-Bänder in der Präsentation
 * (Kapitel-Bild › Concept-Titelbild). Optionale Galerie folgt separat.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_concepts')) {
            return;
        }
        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_concepts', 'image_context_file_id')) {
                $table->unsignedBigInteger('image_context_file_id')->nullable()->after('note');
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'image_path')) {
                $table->string('image_path')->nullable()->after('image_context_file_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_concepts')) {
            return;
        }
        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            foreach (['image_context_file_id', 'image_path'] as $col) {
                if (Schema::hasColumn('foodalchemist_concepts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
