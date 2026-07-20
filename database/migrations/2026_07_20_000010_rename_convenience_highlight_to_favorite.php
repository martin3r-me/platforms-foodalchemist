<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 06·H4b (2026-07-20, Dominique) — Convenience-Highlights → Favoriten verallgemeinert.
 * Der Pool ist nicht mehr auf Convenience-getaggte GPs beschränkt (§4 fällt); jeder
 * approved GP ist als Lieblings-GP pinbar. Convenience bleibt der Property-Tag
 * `tag_is_convenience` — der Generator kann optional darauf verengen.
 *
 * Spalten-Rename: is_convenience_highlight → is_favorite, highlight_rank → favorite_rank.
 * Idempotent (hasColumn-Guards) — läuft nach der Add-Migration (000010 vom 18.07.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_gps', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_gps', 'is_convenience_highlight')
                && ! Schema::hasColumn('foodalchemist_gps', 'is_favorite')) {
                $table->renameColumn('is_convenience_highlight', 'is_favorite');
            }
            if (Schema::hasColumn('foodalchemist_gps', 'highlight_rank')
                && ! Schema::hasColumn('foodalchemist_gps', 'favorite_rank')) {
                $table->renameColumn('highlight_rank', 'favorite_rank');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_gps', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_gps', 'is_favorite')
                && ! Schema::hasColumn('foodalchemist_gps', 'is_convenience_highlight')) {
                $table->renameColumn('is_favorite', 'is_convenience_highlight');
            }
            if (Schema::hasColumn('foodalchemist_gps', 'favorite_rank')
                && ! Schema::hasColumn('foodalchemist_gps', 'highlight_rank')) {
                $table->renameColumn('favorite_rank', 'highlight_rank');
            }
        });
    }
};
