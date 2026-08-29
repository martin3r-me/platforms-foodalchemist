<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workstream W (MCP-Steuerbarkeit D5c, 2026-08-29): Wissens-Erdung des Konzept-Wordings
 * `concept.wording` an den Cross-Cutting-Fakten (Anti-Marker / Substitutionen / Mengen-Defaults) —
 * als Guardrail für kundensichtbaren Text. Vorher lief das Wording (Concepter-Editor + künftig MCP
 * concept_wording.GENERATE) OHNE Wissens-Kanal (nur Bindings + Food-DNA). Kein Regelwerk-Kanal:
 * Wording ist Brand-Voice, kein Bau-Regelwerk (regelwerkBlock würde sonst den Basisrezept-Fallback
 * ziehen — bewusst NICHT geroutet). Wirkt Web + MCP gemeinsam (Parität).
 *
 * Spiegel von KnowledgeImportCommand::seedRoutings — beide Wege müssen dasselbe setzen.
 */
return new class extends Migration
{
    private const ROWS = [
        ['concept.wording', 'cross_cutting', 'always', null, null],
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
            ->where('feature', 'concept.wording')
            ->where('category', 'cross_cutting')
            ->delete();
    }
};
