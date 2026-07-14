<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * #505: Neue Wissens-Kategorie `skill` — MCP-Orchestrierungs-Workflows
 * (fa.*), searchbar via knowledge.SEARCH/LIST, NICHT always-injiziert
 * (kein Routing-Seed → kein Prompt-Bloat). Idempotent (Existenz-Guard,
 * NULL-team_id dedupt im Unique nicht).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('foodalchemist_knowledge_categories')) {
            return;
        }
        $exists = DB::table('foodalchemist_knowledge_categories')
            ->whereNull('team_id')->where('slug', 'skill')->exists();
        if ($exists) {
            return;
        }
        $sort = (int) DB::table('foodalchemist_knowledge_categories')->max('sort_order') + 10;
        DB::table('foodalchemist_knowledge_categories')->insert([
            'uuid' => (string) Str::uuid(),
            'team_id' => null,
            'slug' => 'skill',
            'label' => 'Skills / MCP-Workflows',
            'sort_order' => $sort,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (DB::getSchemaBuilder()->hasTable('foodalchemist_knowledge_categories')) {
            DB::table('foodalchemist_knowledge_categories')->whereNull('team_id')->where('slug', 'skill')->delete();
        }
    }
};
