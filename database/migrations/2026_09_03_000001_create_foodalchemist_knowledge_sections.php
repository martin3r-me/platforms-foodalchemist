<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W1-2: Wissens-Dokumente in ABSCHNITTE zerlegen — die Grundlage für Chunking (W1-5) und
 * für einen Konformitäts-Prüfblock, der nur das Normative lädt (W3-3).
 *
 * Rein additiv: kein bestehender Lesepfad wird angefasst. Ohne Sectionizer-Lauf bleibt die
 * Tabelle leer und alles verhält sich wie bisher.
 *
 * `anchor` (32) trägt bei Regelwerk-Dossiers den Paragraphen (»§6.1«), sonst eine laufende
 * Marke (»abs-3«, »lead«). Die Längen sind absichtlich knapp: SQLite erzwingt keine
 * VARCHAR-Länge, MySQL/demo schon — der Sectionizer kappt darum defensiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_knowledge_sections')) {
            return;
        }

        Schema::create('foodalchemist_knowledge_sections', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('knowledge_document_id')
                ->constrained('foodalchemist_knowledge_documents')
                ->cascadeOnDelete();
            // Spiegel des Doc-Werts. NULL = global/BHG-kuratiert, wie beim Dokument.
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedInteger('ord');
            $table->string('anchor', 32)->comment('§6.1 | abs-3 | lead');
            $table->string('heading_path', 255)->comment('Überschriften-Kette, geht in den Chunk-Text (W1-5)');
            $table->string('kind', 12)->index()->comment('normativ | referenz | beispiel | changelog | meta | prosa');
            $table->string('title')->nullable();
            $table->longText('body_md');
            $table->unsignedInteger('char_count')->default(0);
            $table->string('content_hash', 64)->index();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['knowledge_document_id', 'ord'], 'fa_know_sec_doc_ord_uq');
            $table->index(['knowledge_document_id', 'kind'], 'fa_know_sec_doc_kind_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_knowledge_sections');
    }
};
