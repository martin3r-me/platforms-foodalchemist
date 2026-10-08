<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 79 · Rückbau des alten Trendradars (Vault-Dossiers `07.03_Trend_Scouting` + KI-Clustering).
 * Trends leben seit Spec 79 im Trendradar-Modul (foodalchemist_trends) und gehen über die Planung in die Kaskade.
 *
 * - Routing-Zeilen `category = trend` (foodbook.plan, concept.brief_geruest) löschen — der trendBlock ist entfernt.
 * - Wissens-Dossiers `category = trend` DEAKTIVIEREN (nicht löschen, reversibel): sie fließen in keinen Prompt mehr.
 *   Die Kategorie selbst bleibt (Altbestand bleibt im Wissens-Browser lesbar).
 * - Cluster-Tabellen foodalchemist_trend_meta + foodalchemist_trend_taxonomy entfernen (abgeleitete KI-Einordnung
 *   der Dossiers, ohne eigene Pflege). down() legt sie leer wieder an.
 * - Spalten source_knowledge_document_id (Sessions/Rezepte/Konzepte) und trend_auto_* (Team-Settings) bleiben im Schema,
 *   werden nur nicht mehr befüllt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_knowledge_routings')) {
            DB::table('foodalchemist_knowledge_routings')->where('category', 'trend')->delete();
        }
        if (Schema::hasTable('foodalchemist_knowledge_documents')) {
            DB::table('foodalchemist_knowledge_documents')->where('category', 'trend')->where('active', 1)
                ->update(['active' => 0, 'updated_at' => now()]);
        }
        Schema::dropIfExists('foodalchemist_trend_meta');
        Schema::dropIfExists('foodalchemist_trend_taxonomy');
    }

    public function down(): void
    {
        // Leere Tabellen wie in 2026_08_02_000001 (Inhalt war abgeleitet und wird nicht wiederhergestellt).
        if (! Schema::hasTable('foodalchemist_trend_taxonomy')) {
            Schema::create('foodalchemist_trend_taxonomy', function ($table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->string('category', 48);
                $table->string('trend_class', 64)->nullable();
                $table->string('slug', 96);
                $table->text('description')->nullable();
                $table->integer('sort_order')->default(0);
                $table->string('status', 16)->default('approved');
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }
        if (! Schema::hasTable('foodalchemist_trend_meta')) {
            Schema::create('foodalchemist_trend_meta', function ($table) {
                $table->id();
                $table->unsignedBigInteger('knowledge_document_id')->unique();
                $table->unsignedBigInteger('trend_taxonomy_id')->nullable();
                $table->string('cluster_id', 96)->nullable();
                $table->string('category', 48)->nullable();
                $table->string('trend_class', 64)->nullable();
                $table->string('maturity', 16)->nullable();
                $table->boolean('is_hype')->default(false);
                $table->string('relevance', 8)->nullable();
                $table->decimal('confidence', 4, 3)->nullable();
                $table->string('method', 16)->nullable();
                $table->string('status', 16)->default('tentative');
                $table->timestamps();
            });
        }
    }
};
