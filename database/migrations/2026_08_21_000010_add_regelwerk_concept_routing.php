<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 41 B1 (2026-08-21): das Regelwerk wird PRO FEATURE geroutet (KnowledgeContextService::
 * regelwerkBlock wählt jetzt Slug + Extraktionsfenster nach Feature).
 *
 * 1) `concept.brief_geruest` → Kategorie `regelwerk`, `always` (Deckel 9000): das REGELWERK
 *    CONCEPT (Archetypen · Container-nie-atomar · Vokabular · Preislogik) fließt hart in den
 *    Gerüst-Generator und verhindert den 1-Position-Kollaps (RC-4 / Fall 003 »Lunchbuffet«).
 *    Wirkt erst zusammen mit dem aktiven Doc `regelwerk.regelwerk_concept`.
 * 2) `ai_generate_recipe` → Deckel 7000 → 9500 anheben, damit neben §2–§4 (~6,5k) auch die neue
 *    §12-Reihenfolge-Region (~2,2k) ins Budget passt (RC-2 / Fälle D4 + E1). Sonst würde §12
 *    weggetruncatet.
 *
 * Spiegel von KnowledgeImportCommand::seedRoutings — beide Wege müssen dasselbe setzen
 * (frisch-migriert == reimportiert). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_knowledge_routings')) {
            return;
        }
        $now = now()->toDateTimeString();

        // 1) Concept-Regelwerk in den Gerüst-Generator (neu).
        DB::table('foodalchemist_knowledge_routings')->insertOrIgnore([
            'feature' => 'concept.brief_geruest', 'category' => 'regelwerk', 'mode' => 'always',
            'max_docs' => 1, 'max_chars_per_doc' => 9000,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // 2) Budget des bestehenden Rezept-Regelwerk-Routings anheben (§12 muss reinpassen).
        DB::table('foodalchemist_knowledge_routings')
            ->where('feature', 'ai_generate_recipe')
            ->where('category', 'regelwerk')
            ->where('mode', 'always')
            ->update(['max_chars_per_doc' => 9500, 'updated_at' => $now]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_knowledge_routings')) {
            return;
        }
        DB::table('foodalchemist_knowledge_routings')
            ->where('feature', 'concept.brief_geruest')
            ->where('category', 'regelwerk')
            ->delete();

        DB::table('foodalchemist_knowledge_routings')
            ->where('feature', 'ai_generate_recipe')
            ->where('category', 'regelwerk')
            ->where('mode', 'always')
            ->update(['max_chars_per_doc' => 7000, 'updated_at' => now()->toDateTimeString()]);
    }
};
