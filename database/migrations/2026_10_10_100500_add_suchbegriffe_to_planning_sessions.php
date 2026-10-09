<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 80 Teil A — Suchbegriffe je Planung.
 *
 * Aus dem Briefing abgeleitete (und vom Menschen korrigierte) Suchbegriffe, je Erstell-Tab ein Satz:
 * `{rezept: [{t, g, q}], gericht: [...], concept: [...]}` mit t = Begriff, g = Gruppe
 * (zutaten|komponenten|techniken|aromen|eigene), q = Quelle (ki|mensch). Wissen, Bestand und Pairing
 * suchen damit statt mit dem Briefing-Rohtext (Anlass demo Lauf #79).
 *
 * Additiv/idempotent (hasColumn-Guard), nullable, kein Backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_planning_sessions')) {
            return;
        }
        Schema::table('foodalchemist_planning_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_planning_sessions', 'suchbegriffe')) {
                $table->json('suchbegriffe')->nullable()
                    ->comment('Spec 80: Suchbegriffe je Erstell-Tab {rezept|gericht|concept: [{t,g,q}]}');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_planning_sessions')) {
            return;
        }
        Schema::table('foodalchemist_planning_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_planning_sessions', 'suchbegriffe')) {
                $table->dropColumn('suchbegriffe');
            }
        });
    }
};
