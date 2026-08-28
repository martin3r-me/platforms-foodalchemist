<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workstream W (MCP-Steuerbarkeit D2c, 2026-08-29): Wissens-Erdung der Freitext-Revision
 * `recipe.ueberarbeiten` am REGELWERK BASISREZEPTE — analog zur Generator-Erdung
 * (2026_08_14_000010_add_regelwerk_recipe_routing). Vorher lief die Revision (Editor +
 * künftig MCP recipes.REVISE) OHNE Regelwerk-Kanal (nur Bindings). Diese Zeile routet den
 * Regelwerk-Block in `recipe.ueberarbeiten`; KnowledgeContextService::regelwerkBlock wählt
 * über den Feature-Fallback den Basisrezepte-Slug. Wirkt Web + MCP gemeinsam (Parität).
 *
 * Spiegel von KnowledgeImportCommand::seedRoutings — beide Wege müssen dasselbe setzen
 * (frisch-migriert == reimportiert).
 */
return new class extends Migration
{
    private const ROWS = [
        ['recipe.ueberarbeiten', 'regelwerk', 'always', 1, 7000],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_knowledge_routings')) {
            return;
        }
        $now = now()->toDateTimeString();
        foreach (self::ROWS as [$feature, $category, $mode, $maxDocs, $maxChars]) {
            DB::table('foodalchemist_knowledge_routings')->insertOrIgnore([
                'feature' => $feature, 'category' => $category, 'mode' => $mode,
                'max_docs' => $maxDocs, 'max_chars_per_doc' => $maxChars,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_knowledge_routings')) {
            return;
        }
        DB::table('foodalchemist_knowledge_routings')
            ->where('feature', 'recipe.ueberarbeiten')
            ->where('category', 'regelwerk')
            ->delete();
    }
};
