<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M5-01 / ⚠D4 (ENTSCHIEDEN 2026-06-11): Wissens-Tabellen (nur Klasse A) +
 * Pairing-Graph (D-7-MVP). Pairing-Welt kommt STRUKTURIERT aus der Quell-DB
 * (der Quell-Parser hat die 767 MDs bereits zu Kanten/Mappings verarbeitet);
 * der MD-Re-Parser hängt am wiederholbaren knowledge-import (Updates).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('NULL = global/BHG-kuratiert (D1)');
            $table->string('slug')->unique();
            $table->string('titel');
            $table->string('kategorie', 24)->index()->comment('cross_cutting|domain|pairing|regelwerk_snippet');
            $table->longText('inhalt_md');
            $table->unsignedInteger('version')->default(1);
            $table->string('content_hash', 64)->comment('sha256 — unverändert ⇒ skip (idempotent)');
            $table->unsignedInteger('char_count');
            $table->boolean('aktiv')->default(true);
            $table->string('quelle_pfad')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('foodalchemist_knowledge_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('alias_slug')->unique()->comment('Seed: 258 Paare aus vault_context.rs:39-322');
            $table->foreignId('knowledge_document_id')->constrained('foodalchemist_knowledge_documents')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('foodalchemist_knowledge_routings', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 64);
            $table->string('kategorie', 24);
            $table->string('modus', 16)->comment('always|discovery|grounding|none');
            $table->unsignedInteger('max_docs')->nullable();
            $table->unsignedInteger('max_chars_per_doc')->nullable();
            $table->timestamps();

            $table->unique(['feature', 'kategorie']);
        });

        Schema::create('foodalchemist_vocab_pairing_ankers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('NULL = global (D1)');
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->string('slug')->unique();
            $table->string('display_de');
            $table->foreignId('knowledge_document_id')->nullable()->constrained('foodalchemist_knowledge_documents')->nullOnDelete()
                ->comment('ersetzt file_path (D4/GL-13 §4.3)');
            $table->string('quelle_pfad')->nullable()->comment('Vault-file_path bis zum Knowledge-Link');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('foodalchemist_pairing_anker_edges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('NULL = global (D1)');
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->foreignId('anker_a_id')->constrained('foodalchemist_vocab_pairing_ankers')->cascadeOnDelete();
            $table->foreignId('anker_b_id')->constrained('foodalchemist_vocab_pairing_ankers')->cascadeOnDelete();
            $table->string('typ', 16)->comment('klassisch|modern|kontrast (GL-10)');
            $table->text('evidenz')->nullable();
            $table->string('source_slug')->nullable();
            $table->timestamps();

            $table->unique(['anker_a_id', 'anker_b_id', 'typ'], 'fa_pairing_edges_a_b_typ_unique');
            $table->index('anker_b_id');
        });

        Schema::create('foodalchemist_gp_anker_mappings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('gp_id')->constrained('foodalchemist_gps')->cascadeOnDelete();
            $table->foreignId('anker_id')->constrained('foodalchemist_vocab_pairing_ankers')->cascadeOnDelete();
            $table->string('rolle', 16)->default('kern');
            $table->string('quelle', 16)->nullable()->comment('manual|ai_inferred (GL-07)');
            $table->decimal('ai_confidence', 4, 3)->nullable();
            $table->text('ai_begruendung')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['gp_id', 'anker_id']);
        });

        Schema::create('foodalchemist_recipe_anker_mappings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->foreignId('recipe_id')->constrained('foodalchemist_recipes')->cascadeOnDelete();
            $table->foreignId('anker_id')->constrained('foodalchemist_vocab_pairing_ankers')->cascadeOnDelete();
            $table->string('rolle', 16)->default('kern');
            $table->string('quelle', 16)->nullable();
            $table->decimal('ai_confidence', 4, 3)->nullable();
            $table->text('ai_begruendung')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['recipe_id', 'anker_id']);
        });

        Schema::create('foodalchemist_recipe_pairings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->foreignId('recipe_id')->constrained('foodalchemist_recipes')->cascadeOnDelete();
            $table->foreignId('anker_id')->constrained('foodalchemist_vocab_pairing_ankers')->cascadeOnDelete();
            $table->string('typ', 16)->comment('klassisch|kontrast|verbund|trinitas');
            $table->string('konfidenz', 16)->default('medium');
            $table->text('note')->nullable();
            $table->string('created_via', 16)->nullable()->comment('gemini|manual|pairing_doc');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['recipe_id', 'anker_id', 'typ']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_recipe_pairings');
        Schema::dropIfExists('foodalchemist_recipe_anker_mappings');
        Schema::dropIfExists('foodalchemist_gp_anker_mappings');
        Schema::dropIfExists('foodalchemist_pairing_anker_edges');
        Schema::dropIfExists('foodalchemist_vocab_pairing_ankers');
        Schema::dropIfExists('foodalchemist_knowledge_routings');
        Schema::dropIfExists('foodalchemist_knowledge_aliases');
        Schema::dropIfExists('foodalchemist_knowledge_documents');
    }
};
