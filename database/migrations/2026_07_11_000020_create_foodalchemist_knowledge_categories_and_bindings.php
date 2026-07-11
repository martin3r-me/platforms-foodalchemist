<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wissens-Modul v2 (Roadmap #469): pflegbares Kategorien-Vokabular + feine Bindungen
 * (Doc → KI-Layer / Warengruppe). Spec: 15_GITHUB/_Wissensmodul_Spec.md.
 * Kategorie bleibt Slug-String auf knowledge_documents (weiche Referenz), Vokabular = gültige/gelabelte Kategorien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_knowledge_categories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('NULL = global');
            $table->string('slug', 48);
            $table->string('label');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['team_id', 'slug'], 'fa_know_cat_team_slug_uq');
        });

        Schema::create('foodalchemist_knowledge_bindings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('knowledge_document_id')
                ->constrained('foodalchemist_knowledge_documents', 'id', 'fa_know_bind_doc_fk')
                ->cascadeOnDelete();
            $table->string('binding_type', 32)->comment('ki_layer | warengruppe (erweiterbar)');
            $table->string('target_key', 64)->comment('Feature-Name bzw. Warengruppen-Code');
            $table->string('mode', 16)->nullable()->comment('always|discovery|grounding|reference');
            $table->integer('weight')->default(0);
            $table->boolean('active')->default(true);
            $table->string('source', 16)->default('ui')->comment('ui|mcp|import|frontmatter');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['knowledge_document_id', 'binding_type', 'target_key'], 'fa_know_bind_uq');
            $table->index(['binding_type', 'target_key'], 'fa_know_bind_target_ix');
        });

        // Kategorien-Vokabular seeden: feste Basis-Menge (robust auf frischer DB) + evtl. weitere aus Bestand.
        // Existenz-Guard je slug, weil MySQL bei team_id=NULL im Unique NICHT dedupt (NULL != NULL).
        $labels = [
            'domain'        => 'Domänen-Wissen',
            'cross_cutting' => 'Cross-Cutting-Regeln',
            'pairing'       => 'Flavor Pairing',
            'regelwerk'     => 'Regelwerke',
            'trend'         => 'Trends',
            'kueche'        => 'Küchen & Techniken',
            'niveau'        => 'Niveau-System',
        ];
        $slugs = array_values(array_unique(array_merge(
            array_keys($labels),
            DB::table('foodalchemist_knowledge_documents')->whereNotNull('category')->distinct()->pluck('category')->all()
        )));
        $sort = 0;
        foreach ($slugs as $slug) {
            if ($slug === null || $slug === '') continue;
            $sort += 10;
            $exists = DB::table('foodalchemist_knowledge_categories')
                ->whereNull('team_id')->where('slug', $slug)->exists();
            if ($exists) continue;
            DB::table('foodalchemist_knowledge_categories')->insert([
                'uuid'        => (string) \Illuminate\Support\Str::uuid(),
                'team_id'     => null,
                'slug'        => $slug,
                'label'       => $labels[$slug] ?? ucfirst(str_replace('_', ' ', $slug)),
                'sort_order'  => $sort,
                'active'      => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_knowledge_bindings');
        Schema::dropIfExists('foodalchemist_knowledge_categories');
    }
};
