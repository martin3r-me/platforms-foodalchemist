<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 42 (Folge) — Format als Live-Rubrik in der Speisekarte (gleiche Logik wie das
 * Foodbook-Format-Kapitel, format_id auf foodalchemist_foodbook_chapters). Eine Rubrik mit
 * gesetztem format_id rendert live aus dem Format (Editionen/Hero/Preis-Range), statt eigene
 * Positionen zu tragen. Nullable, FK-los (wie im Foodbook), idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_menu_card_sections', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_menu_card_sections', 'format_id')) {
                $table->unsignedBigInteger('format_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_menu_card_sections', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_menu_card_sections', 'format_id')) {
                $table->dropColumn('format_id');
            }
        });
    }
};
