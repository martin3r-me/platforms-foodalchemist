<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 79 · Planung aus dem Trendradar: eine Session kann aus einer Kombination von Trends, Hypes und
 * Fundstücken (Inspiration) entstehen. Herkunft als loser Zeiger {trend_ids:[…], fundstueck_ids:[…]} —
 * `source_knowledge_document_id` zeigt auf Wissens-Dossiers und trägt nur eine ID. Additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_planning_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_planning_sessions', 'source_trend_refs')) {
                $table->json('source_trend_refs')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_planning_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_planning_sessions', 'source_trend_refs')) {
                $table->dropColumn('source_trend_refs');
            }
        });
    }
};
