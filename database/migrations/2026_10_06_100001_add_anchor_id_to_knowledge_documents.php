<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Support\DossierAnker;

/**
 * Spec 60 · Schritt 1: Wissensdokument → Aroma-Anker (nullable, kein FK: Anker-Vokabular ist
 * global und wird re-importiert; ein verschwundener Anker soll das Dossier nicht löschen).
 *
 * Nachtrag für den Bestand deterministisch aus dem Frontmatter (anker_id + anker_slug müssen
 * zum Vokabular passen), in Blöcken, damit 12.000 Dossiers nicht auf einmal im Speicher liegen.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: in der fa-pass-Variante lag dieselbe Spalte als Kopie unter
        // 2026_10_06_000002_add_anchor_id_to_knowledge_documents — auf diesen DBs gibt es
        // Spalte und Index schon („Duplicate column name 'anchor_id'").
        if (! Schema::hasColumn('foodalchemist_knowledge_documents', 'anchor_id')) {
            Schema::table('foodalchemist_knowledge_documents', function (Blueprint $table) {
                $table->unsignedBigInteger('anchor_id')->nullable()->after('category');
            });
        }
        if (! Schema::hasIndex('foodalchemist_knowledge_documents', 'fa_knowledge_anchor_idx')) {
            Schema::table('foodalchemist_knowledge_documents', function (Blueprint $table) {
                $table->index('anchor_id', 'fa_knowledge_anchor_idx');
            });
        }

        DB::table('foodalchemist_knowledge_documents')
            ->whereNull('anchor_id')
            ->where('content_md', 'like', '%anker_id:%')
            ->select(['id', 'content_md'])
            ->orderBy('id')
            ->chunkById(500, function ($docs) {
                foreach ($docs as $d) {
                    $anker = DossierAnker::ausInhalt($d->content_md);
                    if ($anker !== null) {
                        DB::table('foodalchemist_knowledge_documents')->where('id', $d->id)->update(['anchor_id' => $anker]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_knowledge_documents', function (Blueprint $table) {
            $table->dropIndex('fa_knowledge_anchor_idx');
            $table->dropColumn('anchor_id');
        });
    }
};
