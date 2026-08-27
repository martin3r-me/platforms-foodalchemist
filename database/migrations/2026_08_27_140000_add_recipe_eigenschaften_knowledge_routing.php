<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #2-A (Dominique 2026-08-27): die Eigenschaften-KI (recipe.eigenschaften) bekommt gezieltes Wissen,
 * damit sie Zeiten/Funktion belastbar schätzt statt nur aus Name+Zutaten:
 *  - produktion_kapazitat (always): das Produktions-Zeitkennwerte-Dossier (Rüst-/Vorgangs-/variable
 *    Zeit, Kalibrier-Set) — rezept-UNABHÄNGIGE Referenz, die per discovery NIE getroffen würde
 *    (Slug-Jaccard gegen die Rezept-Beschreibung = 0) → generischer always-Loader
 *    (KnowledgeContextService::alwaysCategoryBlock).
 *  - regelwerk (always): das Basisrezepte-Regelwerk (regelwerkBlock fällt für dieses Feature auf
 *    den %basisrezept%-Slug zurück, Zeile 448).
 *
 * Spiegel von KnowledgeImportCommand::seedRoutings — beide Wege müssen dasselbe setzen
 * (frisch-migriert == reimportiert). Idempotent (unique feature+category → insertOrIgnore).
 */
return new class extends Migration
{
    private const ROWS = [
        // feature, category, mode, max_docs, max_chars_per_doc
        ['recipe.eigenschaften', 'produktion_kapazitat', 'always', 3, 7000],
        ['recipe.eigenschaften', 'regelwerk', 'always', 1, 6000],
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
            ->where('feature', 'recipe.eigenschaften')
            ->whereIn('category', ['produktion_kapazitat', 'regelwerk'])
            ->delete();
    }
};
