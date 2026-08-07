<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S1 (Wissens-Skalierbarkeit, 2026-08-07): suggestive, WACHSENDE Kategorien als `discovery`-Quelle
 * für den Basisrezept-Generator (`ai_generate_recipe`). Heute waren `niveau`, `kueche`, `kreativ_input`
 * UNGEROUTET → beim Auffüllen der Docs wirkte nichts. Mit `discovery` (kein `always`) trägt jedes neue
 * Doc automatisch, gedeckelt durch top_k/chars — der Prompt wächst NICHT mit der Doc-Zahl (O(1)).
 *
 * Wirkung erst zusammen mit dem generischen discovery-Branch in KnowledgeContextService::contextFor
 * (discoverGenericBlock) — eine Routing-Zeile allein lädt nichts. Spiegel von
 * KnowledgeImportCommand::seedRoutings — beide Wege müssen dasselbe setzen (frisch-migriert ==
 * reimportiert). Niveau top_k=1: parametrisch, nur die aktive Stufe (Leitplanken-Wert augmentiert die Query).
 */
return new class extends Migration
{
    private const ROWS = [
        ['ai_generate_recipe', 'niveau', 'discovery', 1, 3000],
        ['ai_generate_recipe', 'kueche', 'discovery', 3, 3000],
        ['ai_generate_recipe', 'kreativ_input', 'discovery', 3, 2000],
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
            ->whereIn('category', ['niveau', 'kueche', 'kreativ_input'])
            ->delete();
    }
};
