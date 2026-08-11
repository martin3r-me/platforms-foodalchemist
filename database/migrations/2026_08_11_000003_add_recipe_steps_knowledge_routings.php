<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Step-by-Step-KI bekommt denselben dynamischen Wissenszugriff wie die Generatoren,
 * aber enger: Technik-/Domain-Wissen statt kreativer Inspiration. Semantische Treffer
 * kommen nur dazu, wenn FOODALCHEMIST_SEMANTIC_SEARCH/Qdrant aktiv ist.
 */
return new class extends Migration
{
    private const ROWS = [
        ['recipe.steps', 'cross_cutting', 'always', null, null],
        ['recipe.steps', 'domain', 'discovery', null, null],
        ['recipe.steps', 'kueche', 'discovery', 3, 3000],
        ['recipe.steps', 'niveau', 'discovery', 1, 3000],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_knowledge_routings')) {
            return;
        }

        $now = now()->toDateTimeString();
        foreach (self::ROWS as [$feature, $category, $mode, $maxDocs, $maxChars]) {
            DB::table('foodalchemist_knowledge_routings')->insertOrIgnore([
                'feature' => $feature,
                'category' => $category,
                'mode' => $mode,
                'max_docs' => $maxDocs,
                'max_chars_per_doc' => $maxChars,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_knowledge_routings')) {
            return;
        }

        DB::table('foodalchemist_knowledge_routings')
            ->where('feature', 'recipe.steps')
            ->whereIn('category', ['cross_cutting', 'domain', 'kueche', 'niveau'])
            ->delete();
    }
};
