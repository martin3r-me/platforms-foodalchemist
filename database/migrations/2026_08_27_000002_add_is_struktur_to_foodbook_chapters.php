<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Textkapitel/Sektion (Dominique 2026-08-27): `is_struktur` markiert ein Foodbook-Kapitel als
 * reine Sektion/Überschrift OHNE eigenes Food (Intro, „Organisation & Umsetzung", eine
 * Format-Sektion). Solche Kapitel „kommen im Food nicht mit" — kein eigenes Food, im Board als
 * Sektionszeile ohne Food-Chrome; den Σ-Preis ihrer Food-Unterkapitel zeigen sie weiterhin
 * (Orientierung). Getrennt von released_at/status/fortschritt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_foodbook_chapters')) {
            return;
        }

        Schema::table('foodalchemist_foodbook_chapters', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_foodbook_chapters', 'is_struktur')) {
                $table->boolean('is_struktur')->default(false);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_foodbook_chapters')) {
            return;
        }

        Schema::table('foodalchemist_foodbook_chapters', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_foodbook_chapters', 'is_struktur')) {
                $table->dropColumn('is_struktur');
            }
        });
    }
};
