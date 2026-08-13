<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planung-Leitstelle: die Richtungs-Regler (Niveau/Convenience/Bio/Frische/Diät/
 * Aroma; Gericht zusätzlich Anlass/Serviceform/Kompositions-Stil/Ziel-VK) werden am
 * Planung-Go gesetzt und in den Kaskaden-Fan-out vererbt. Vererbungs-Home = ein
 * JSON-Bündel an der Session (die Lineage-Wurzel, die jeder Fan-out-Schritt ohnehin
 * lädt). Idempotent (hasColumn-Guard) — kein Deutsch im Schema (Regelwerk).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('foodalchemist_planning_sessions', 'generation_params')) {
            return;
        }
        Schema::table('foodalchemist_planning_sessions', function (Blueprint $table) {
            $table->json('generation_params')->nullable()->after('creative_mode');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('foodalchemist_planning_sessions', 'generation_params')) {
            return;
        }
        Schema::table('foodalchemist_planning_sessions', function (Blueprint $table) {
            $table->dropColumn('generation_params');
        });
    }
};
