<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R6.1: created_via-Lineage am Konzept (Spiegel von recipes.created_via) — der
 * Konzept-Generator (Brief/Gerüst, UI/MCP) markiert seine Drafts nachvollziehbar.
 * KI-Schreibpfade: immer status=draft + created_via (globale DoD).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_concepts', 'created_via')) {
            Schema::table('foodalchemist_concepts', function (Blueprint $table) {
                $table->string('created_via', 48)->nullable()->index(); // editor | concept_generator_ui|_mcp | concept_generator_brief_*
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_concepts', 'created_via')) {
            Schema::table('foodalchemist_concepts', function (Blueprint $table) {
                $table->dropColumn('created_via');
            });
        }
    }
};
