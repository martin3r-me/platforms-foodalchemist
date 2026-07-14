<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * #505: Wissens-Kategorie `skill` → `workflow` umbenennen (Slug `workflow`,
 * Label „Alchemist-Workflows"). „skill" überlud den Plattform-Begriff
 * (skill_registry) — das sind FA-Handlungs-Abläufe. `workflow` wird angelegt,
 * `skill` deaktiviert (Docs werden separat auf `workflow` umgezogen). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('foodalchemist_knowledge_categories')) {
            return;
        }
        $exists = DB::table('foodalchemist_knowledge_categories')
            ->whereNull('team_id')->where('slug', 'workflow')->exists();
        if (! $exists) {
            $sort = (int) DB::table('foodalchemist_knowledge_categories')->max('sort_order') + 10;
            DB::table('foodalchemist_knowledge_categories')->insert([
                'uuid' => (string) Str::uuid(),
                'team_id' => null,
                'slug' => 'workflow',
                'label' => 'Alchemist-Workflows',
                'sort_order' => $sort,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        // Bestehende Docs mitziehen: Kategorie + Slug-Präfix (skill.* → workflow.*), dann Alt-Kategorie deaktivieren.
        DB::table('foodalchemist_knowledge_documents')->where('category', 'skill')->update(['category' => 'workflow', 'updated_at' => now()]);
        DB::table('foodalchemist_knowledge_documents')->where('slug', 'like', 'skill.%')
            ->update(['slug' => DB::raw("REPLACE(slug, 'skill.', 'workflow.')"), 'updated_at' => now()]);
        // Vault-Ordner Skills/ → Workflows/: source_path mitziehen, sonst matcht ein Re-Import nicht (Dublette).
        DB::table('foodalchemist_knowledge_documents')->where('source_path', 'like', '%/Skills/%')
            ->update(['source_path' => DB::raw("REPLACE(source_path, '/Skills/', '/Workflows/')"), 'updated_at' => now()]);
        DB::table('foodalchemist_knowledge_categories')->whereNull('team_id')->where('slug', 'skill')->update(['active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('foodalchemist_knowledge_categories')) {
            return;
        }
        DB::table('foodalchemist_knowledge_categories')->whereNull('team_id')->where('slug', 'skill')->update(['active' => true, 'updated_at' => now()]);
        DB::table('foodalchemist_knowledge_documents')->where('category', 'workflow')->update(['category' => 'skill', 'updated_at' => now()]);
        DB::table('foodalchemist_knowledge_categories')->whereNull('team_id')->where('slug', 'workflow')->delete();
    }
};
