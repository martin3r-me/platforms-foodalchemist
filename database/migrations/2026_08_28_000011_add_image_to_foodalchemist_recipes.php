<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 43 (Bild-Epic) — Gericht-Foto: ein Titelbild pro Rezept/Gericht. Wird optional
 * in der Präsentation neben der Gericht-Zeile gezeigt (Builder-Toggle „Gericht-Fotos").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_recipes', 'image_context_file_id')) {
                $table->unsignedBigInteger('image_context_file_id')->nullable()->after('id');
            }
            if (! Schema::hasColumn('foodalchemist_recipes', 'image_path')) {
                $table->string('image_path')->nullable()->after('image_context_file_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            foreach (['image_context_file_id', 'image_path'] as $c) {
                if (Schema::hasColumn('foodalchemist_recipes', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
