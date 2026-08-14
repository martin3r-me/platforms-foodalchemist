<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etappe 1 (Planung »Mise en Place«, 2026-08-14): Wissens-Erdung des Rezept-Generators am
 * REGELWERK BASISREZEPTE. Die Kategorie `regelwerk` war importiert (KnowledgeImportCommand:46),
 * aber UNGEROUTET → §2/§3/§4 (Verarbeitungs-Reduktion · Pürees · Sub-Rezept-Hierarchie) flossen
 * nie in den Prompt. Diese Zeile routet sie in `ai_generate_recipe`.
 *
 * Bewusst `always` (nicht discovery wie 2026_08_07_000001): Regelwerk ist HANDWERK, kein
 * Produkt-Dossier — die generische Beschreibungs-Discovery (Slug-Token gegen die Zutaten) würde
 * es bei realen Rezept-Briefs NIE treffen (kein Overlap »Steinpilz« ↔ »basisrezepte«). Wirkung
 * über den dedizierten KnowledgeContextService::regelwerkBlock (wählt den Basisrezepte-Slug,
 * extrahiert die §2–§4-Region). Deckel: 1 Doc, ~7k Chars.
 *
 * Spiegel von KnowledgeImportCommand::seedRoutings — beide Wege müssen dasselbe setzen
 * (frisch-migriert == reimportiert).
 */
return new class extends Migration
{
    private const ROWS = [
        ['ai_generate_recipe', 'regelwerk', 'always', 1, 7000],
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
            ->where('feature', 'ai_generate_recipe')
            ->where('category', 'regelwerk')
            ->delete();
    }
};
