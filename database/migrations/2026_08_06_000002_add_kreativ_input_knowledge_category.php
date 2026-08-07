<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inspire-Umbau — neue Wissens-Kategorie `kreativ_input` (Dominique 2026-08-06):
 * eigener Topf für die Inspire-Kreativ-Dossiers, damit sie sich nicht mit den
 * bestehenden Wissens-Kategorien (pairing/domain/…) vermischen.
 *
 * Global (team_id = NULL) wie alle Bestands-Kategorien → `assertKategorie` akzeptiert
 * sie für jedes Team (inkl. demo). Idempotent (manueller Guard, weil ein NULL-team_id
 * den UNIQUE(team_id,slug)-Index NICHT greift — NULL ≠ NULL in SQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('foodalchemist_knowledge_categories')) {
            return;
        }
        $exists = DB::table('foodalchemist_knowledge_categories')
            ->whereNull('team_id')->where('slug', 'kreativ_input')->exists();
        if ($exists) {
            return;
        }
        DB::table('foodalchemist_knowledge_categories')->insert([
            'uuid' => (string) Str::uuid7(),
            'team_id' => null,
            'slug' => 'kreativ_input',
            'label' => 'Kreativ-Input',
            'description' => 'Kreativ-Nachschlagewerk pro Aroma-Anker (Inspire-geerdet). '
                .'Reference-only — nie Graph-Kanten.',
            'sort_order' => 80,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('foodalchemist_knowledge_categories')) {
            return;
        }
        DB::table('foodalchemist_knowledge_categories')
            ->whereNull('team_id')->where('slug', 'kreativ_input')->delete();
    }
};
