<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Trendradar (Feature #FA-Trendradar): zweistufige Trend-Taxonomie (Kategorie → Klasse)
 * + Cluster-/Zuordnungs-Metadaten je Trend-Wissens-Dokument.
 *
 * FA konsumiert die schon importierten Trend-Docs (knowledge_documents WHERE category='trend')
 * und clustert sie hier in eine kuratierte Struktur. KEINE eigene Trend-Erfassung —
 * diese Tabellen sind Metadaten ÜBER den bestehenden Docs.
 *
 * Muster: WaWi warengruppe→sub_kategorie bzw. LA-First (tentative → Review → approved).
 * Schema englisch (Golden Rule). Fachbegriffe erscheinen erst im UI wieder deutsch.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Zweistufiges Vokabular. category-Zeile = trend_class NULL (Ebene 1),
        // Klassen-Zeile = category + trend_class gesetzt (Ebene 2, Unterkategorie).
        Schema::create('foodalchemist_trend_taxonomy', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('NULL = global');
            $table->string('category', 48)->comment('Ebene 1 (Food, Kueche, Getraenke, Deko, Location, Personal)');
            $table->string('trend_class', 64)->nullable()->comment('Ebene 2; NULL = Kategorie-Zeile selbst');
            $table->string('slug', 96)->comment('category-slug bzw. category.class-slug');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('status', 16)->default('approved')->comment('approved | tentative (Review-Queue)');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['team_id', 'slug'], 'fa_trend_tax_team_slug_uq');
            $table->index(['category', 'trend_class'], 'fa_trend_tax_cat_class_ix');
        });

        // 1 Zeile je Trend-Doc: Cluster-Ergebnis + geparste Facetten (denormalisiert für Filter).
        Schema::create('foodalchemist_trend_meta', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('knowledge_document_id')
                ->constrained('foodalchemist_knowledge_documents', 'id', 'fa_trend_meta_doc_fk')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('trend_taxonomy_id')->nullable()->index()
                ->comment('zugeordnete Klassen-Zeile in foodalchemist_trend_taxonomy');
            $table->string('cluster_id', 64)->nullable()->index()->comment('Ähnlichkeits-Cluster-Schlüssel');
            $table->string('category', 48)->nullable()->comment('denormalisiert für Facetten');
            $table->string('trend_class', 64)->nullable();
            $table->string('maturity', 16)->nullable()->comment('niche | emerging | mainstream | declining');
            $table->boolean('is_hype')->default(false);
            $table->string('relevance', 16)->nullable()->comment('high | medium | low (aus Frontmatter)');
            $table->float('confidence')->nullable();
            $table->string('method', 24)->nullable()->comment('z.B. embedding+ai | manual');
            $table->string('status', 16)->default('tentative')->comment('tentative | approved');
            $table->timestamps();
            $table->unique('knowledge_document_id', 'fa_trend_meta_doc_uq');
            $table->index(['category', 'trend_class'], 'fa_trend_meta_cat_class_ix');
        });

        // Kategorie-Ebene (Ebene 1) seeden — aus dem Trendradar-Transkript (Sarah Spork, 2026-07-31).
        // Mobiliar bewusst raus (keine Logistik-Anbindung), Getraenke neu aufgenommen.
        $kategorien = [
            'food'      => 'Food',
            'kueche'    => 'Küche',
            'getraenke' => 'Getränke',
            'deko'      => 'Deko',
            'location'  => 'Location',
            'personal'  => 'Personal',
        ];
        $sort = 0;
        foreach ($kategorien as $slug => $label) {
            $sort += 10;
            // Existenz-Guard: MySQL dedupt team_id=NULL im Unique NICHT (NULL != NULL).
            $exists = DB::table('foodalchemist_trend_taxonomy')
                ->whereNull('team_id')->where('slug', $slug)->exists();
            if ($exists) {
                continue;
            }
            DB::table('foodalchemist_trend_taxonomy')->insert([
                'uuid'        => (string) Str::uuid(),
                'team_id'     => null,
                'category'    => $slug,
                'trend_class' => null,
                'slug'        => $slug,
                'description' => $label,
                'sort_order'  => $sort,
                'status'      => 'approved',
                'active'      => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_trend_meta');
        Schema::dropIfExists('foodalchemist_trend_taxonomy');
    }
};
