<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W1-5-Vorbereitung: REGISTER der Embedding-Chunks.
 *
 * Warum eine eigene Tabelle statt „einfach embedden": der Embedding-Store-Contract bietet
 * laut eigenem Docblock KEINE Enumeration — man kann nicht fragen „welche Vektoren liegen
 * für dieses Doc?". Ohne Register lässt sich ein Purge nicht führen, und genau daran liegen
 * heute die verwaisten Wissens-Vektoren (1.018 MySQL-Zeilen bei 598 aktiven Docs).
 * Die Tombstones (softDeletes) sind darum der Purge-Griff, nicht Zierde.
 *
 * `entity_key` ist die Store-Identität (»<docId>#<nn>«) — String, weil `core_embeddings`
 * `entity_id` als string(64) führt und `embedAndStore` int|string nimmt. Chunking braucht
 * also keinen Core-Change.
 *
 * Rein additiv; ohne Chunk-Lauf bleibt die Tabelle leer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_knowledge_chunks')) {
            return;
        }

        Schema::create('foodalchemist_knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('knowledge_section_id')
                ->constrained('foodalchemist_knowledge_sections')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('knowledge_document_id')->index();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('category', 32)->index();
            // »Regal« als Metadata-Filter im Vektor-Store, NICHT als eigener entity_type:
            // searchMerged schleift über Partitionen, drei Regale × N Partitionen wären 3N
            // Queries UND 3N Query-Embeddings.
            $table->string('regal', 24)->index()->comment('Metadata-Filter im Store, kein eigener entity_type');
            $table->unsignedInteger('ord');
            $table->string('entity_key', 64)->unique()->comment('<docId>#<nn> — Store-Identität');
            $table->longText('embed_text');
            $table->unsignedInteger('char_start')->default(0);
            $table->unsignedInteger('char_end')->default(0);
            $table->unsignedInteger('char_count')->default(0);
            $table->string('content_hash', 64)->index();
            $table->timestamp('embedded_at')->nullable()->index()->comment('Wiederaufnahme-Marke für lange Läufe');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['knowledge_document_id', 'ord'], 'fa_know_chunk_doc_ord_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_knowledge_chunks');
    }
};
