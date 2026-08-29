<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workstream W (MCP-Steuerbarkeit D7, 2026-08-29): Wissens-Erdung des Foodbook-Kundentexts
 * `foodbook.kundentext` (Buch- + Kapitel-Ebene, geteilter Prompt-Key) an den Cross-Cutting-Fakten
 * (Anti-Marker / Substitutionen / Mengen) — Guardrail fürs kundensichtbare Wording. Vorher lief der
 * Kundentext (Foodbook-Editor + künftig MCP foodbook.KUNDENTEXT_GENERATE) OHNE Wissens-Kanal
 * (nur Bindings + Food-DNA). Kein Regelwerk-Kanal (Brand-Voice, kein Bau-Regelwerk). Wirkt Web + MCP.
 *
 * Spiegel von KnowledgeImportCommand::seedRoutings.
 */
return new class extends Migration
{
    private const ROWS = [
        ['foodbook.kundentext', 'cross_cutting', 'always', null, null],
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
            ->where('feature', 'foodbook.kundentext')
            ->where('category', 'cross_cutting')
            ->delete();
    }
};
