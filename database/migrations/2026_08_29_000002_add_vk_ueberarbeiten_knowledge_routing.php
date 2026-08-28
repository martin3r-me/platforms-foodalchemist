<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workstream W (MCP-Steuerbarkeit D3, 2026-08-29): Wissens-Erdung der Gericht-Freitext-Revision
 * `vk.ueberarbeiten` am REGELWERK BASISREZEPTE — Pendant zu 2026_08_29_000001 (recipe.ueberarbeiten).
 * Editor (VkModal) + MCP (verkaufsrezepte.REVISE) fahren dieselbe geerdete Strecke. regelwerkBlock
 * wählt den Basisrezepte-Slug über den Feature-Fallback. Spiegel von KnowledgeImportCommand::seedRoutings.
 */
return new class extends Migration
{
    private const ROWS = [
        ['vk.ueberarbeiten', 'regelwerk', 'always', 1, 7000],
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
            ->where('feature', 'vk.ueberarbeiten')
            ->where('category', 'regelwerk')
            ->delete();
    }
};
