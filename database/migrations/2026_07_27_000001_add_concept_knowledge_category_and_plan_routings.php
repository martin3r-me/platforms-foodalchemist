<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Spec 08 P6 (Wissens-Ebene): Kategorie `concept` = Concepting-Wissen
 * („was ist ein gutes Konzept/Menü" — Dramaturgie, Gang-Aufbau, Anlass-Fit,
 * Balance) + die Planungs-Routings `foodbook.plan` / `concept.plan`.
 *
 * Drei Teile, alle idempotent:
 *  1. Kategorie `concept` anlegen (Vokabular; Befüllung ist ein separater
 *     Daten-Schritt — leere Kategorie = kein Prompt-Block, Invariante 6).
 *  2. Dubletten-Konsolidierung `konzept` → `concept` (Spec-08-Nit). Am
 *     lokalen MySQL-Stand existiert KEINE der beiden Zeilen, auf demo kann
 *     die deutsche Variante liegen — darum defensiv statt blind.
 *  3. Routings in die Bestands-DBs bringen: `KnowledgeImportCommand::seedRoutings`
 *     kennt `foodbook.plan`/`concept.plan` seit E6.4, hat sie aber nur bei einem
 *     Import gesetzt — der `IdeenService::kiDivergenz`-Call auf `foodbook.plan`
 *     lief seither ohne Wissens-Block ins Leere.
 */
return new class extends Migration
{
    /** Routing-Zeilen der beiden Planungs-Features (Spiegel von seedRoutings). */
    private const ROUTINGS = [
        ['foodbook.plan', 'cross_cutting', 'always', null, null],
        ['foodbook.plan', 'domain', 'discovery', null, null],
        ['foodbook.plan', 'concept', 'always', 4, 4000],
        ['concept.plan', 'cross_cutting', 'always', null, null],
        ['concept.plan', 'domain', 'discovery', null, null],
        ['concept.plan', 'concept', 'always', 4, 4000],
    ];

    public function up(): void
    {
        $schema = DB::getSchemaBuilder();

        if ($schema->hasTable('foodalchemist_knowledge_categories')) {
            // 1. Kategorie anlegen (Existenz-Guard je slug — MySQL dedupt team_id=NULL im Unique nicht).
            $exists = DB::table('foodalchemist_knowledge_categories')
                ->whereNull('team_id')->where('slug', 'concept')->exists();
            if (! $exists) {
                $sort = (int) DB::table('foodalchemist_knowledge_categories')->max('sort_order') + 10;
                DB::table('foodalchemist_knowledge_categories')->insert([
                    'uuid' => (string) Str::uuid(),
                    'team_id' => null,
                    'slug' => 'concept',
                    'label' => 'Concepting-Wissen',
                    'description' => 'Menü-/Konzept-Handwerk: Dramaturgie, Gang-Aufbau, Anlass- und Gäste-Fit, Balance.',
                    'sort_order' => $sort,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 2. Deutsche Dublette einsammeln (Docs mitziehen wie beim skill→workflow-Rename),
            //    danach die Alt-Zeile deaktivieren statt löschen (nichts wegwerfen, was wir nicht angelegt haben).
            if ($schema->hasTable('foodalchemist_knowledge_documents')) {
                DB::table('foodalchemist_knowledge_documents')->where('category', 'konzept')
                    ->update(['category' => 'concept', 'updated_at' => now()]);
                DB::table('foodalchemist_knowledge_documents')->where('slug', 'like', 'konzept.%')
                    ->update(['slug' => DB::raw("REPLACE(slug, 'konzept.', 'concept.')"), 'updated_at' => now()]);
            }
            DB::table('foodalchemist_knowledge_categories')->whereNull('team_id')->where('slug', 'konzept')
                ->update(['active' => false, 'updated_at' => now()]);
        }

        if ($schema->hasTable('foodalchemist_knowledge_routings')) {
            $now = now()->toDateTimeString();
            foreach (self::ROUTINGS as [$feature, $kategorie, $modus, $maxDocs, $maxChars]) {
                DB::table('foodalchemist_knowledge_routings')->insertOrIgnore([
                    'feature' => $feature, 'category' => $kategorie, 'mode' => $modus,
                    'max_docs' => $maxDocs, 'max_chars_per_doc' => $maxChars,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $schema = DB::getSchemaBuilder();

        if ($schema->hasTable('foodalchemist_knowledge_routings')) {
            foreach (self::ROUTINGS as [$feature, $kategorie]) {
                DB::table('foodalchemist_knowledge_routings')
                    ->where('feature', $feature)->where('category', $kategorie)->delete();
            }
        }
        if ($schema->hasTable('foodalchemist_knowledge_categories')) {
            DB::table('foodalchemist_knowledge_categories')->whereNull('team_id')->where('slug', 'concept')->delete();
            DB::table('foodalchemist_knowledge_categories')->whereNull('team_id')->where('slug', 'konzept')
                ->update(['active' => true, 'updated_at' => now()]);
        }
        // Bewusst NICHT zurückgedreht: die konzept→concept-Umschreibung an den Dokumenten
        // (welche Zeile vorher deutsch war, weiß nach dem Merge niemand mehr).
    }
};
