<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A (MCP-Schreibkaskade, Weg-A-Ausnahme 2026-07-03): maschineller
 * Erstellungs-Marker für Rezepte. `mcp` = LLM-Client via Tool-Registry
 * (Draft-Quarantäne + spätere Rückholbarkeit FA→WaWi hängen daran).
 * `origin_source` bleibt die menschenlesbare Provenienz-Spur.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('foodalchemist_recipes', 'created_via')) {
            return;
        }
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->string('created_via', 48)->nullable()->index()   // editor | mcp | import | generator
                ->after('last_modified_by');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->dropColumn('created_via');
        });
    }
};
