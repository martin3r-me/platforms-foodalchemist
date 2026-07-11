<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #469 v3 (MCP-Schreiben): Herkunft eines Wissens-Dokuments — analog zu
 * recipes.created_via (Phase-A-MCP-Kaskade). Markiert, ob ein Doc aus dem
 * Vault-Import, aus dem Browser oder per MCP entstanden ist.
 *
 *   import | ui | mcp | null (Alt-/unbekannt)
 *
 * Zweck: Audit + Guard. Der MCP-Schreibpfad darf Vault-verwaltete Docs
 * (source_path != null) nicht überschreiben; created_via macht die Herkunft
 * zusätzlich explizit auswertbar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_knowledge_documents', function (Blueprint $table) {
            $table->string('created_via', 16)->nullable()->after('source_path')
                ->comment('import|ui|mcp — Herkunft des Dokuments');
        });

        // Bestand kam aus dem Vault-Import.
        DB::table('foodalchemist_knowledge_documents')
            ->whereNull('created_via')
            ->whereNotNull('source_path')
            ->update(['created_via' => 'import']);
    }

    public function down(): void
    {
        Schema::table('foodalchemist_knowledge_documents', function (Blueprint $table) {
            $table->dropColumn('created_via');
        });
    }
};
