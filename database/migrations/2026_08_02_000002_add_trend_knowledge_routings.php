<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trendradar: Trend-Wissen (category='trend') als discovery-Quelle in die Planungs-Prompts
 * `foodbook.plan` und `concept.brief_geruest`. Spiegel von KnowledgeImportCommand::seedRoutings
 * — beide Wege müssen dasselbe setzen, sonst driften frisch-migrierte und reimportierte DBs.
 *
 * Wirkung erst mit dem trend:discovery-Branch in KnowledgeContextService::contextFor +
 * dem trendBlock() — eine Routing-Zeile allein lädt nichts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_knowledge_routings')) {
            return;
        }
        $now = now()->toDateTimeString();
        $rows = [
            ['foodbook.plan', 'trend', 'discovery', 5, 1500],
            ['concept.brief_geruest', 'trend', 'discovery', 5, 1500],
        ];
        foreach ($rows as [$feature, $category, $mode, $maxDocs, $maxChars]) {
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
            ->whereIn('feature', ['foodbook.plan', 'concept.brief_geruest'])
            ->where('category', 'trend')->delete();
    }
};
