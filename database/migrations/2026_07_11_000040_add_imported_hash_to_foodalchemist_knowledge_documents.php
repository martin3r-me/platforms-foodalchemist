<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #469 Import-Guard (App-wins-per-Snapshot): FA-Modul ist Master, der Vault-
 * Re-Import darf im Browser kuratierte Docs NICHT überschreiben.
 *
 * `imported_hash` = Snapshot des Inhalts, wie ihn der letzte Vault-Import
 * hinterlassen hat. Der Import überschreibt ein Doc nur noch, wenn
 *   (a) der Vault-Inhalt sich gg. imported_hash geändert hat  UND
 *   (b) content_hash == imported_hash  (das Doc wurde seit dem Import NICHT
 *       in der App editiert — sonst gewinnt die App-Kuration).
 *
 * Backfill: für alle bereits importierten Docs setzen wir imported_hash =
 * content_hash. Das etabliert die ehrliche Baseline „Stand jetzt = importiert"
 * (eine Editier-Historie vor Einführung des Guards existiert nicht). App-eigene
 * Docs (source_path NULL) bleiben NULL — sie werden vom Import ohnehin nie
 * angefasst (Slug-Kollision → _2-Suffix).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_knowledge_documents', function (Blueprint $table) {
            $table->string('imported_hash', 64)->nullable()->after('content_hash')
                ->comment('sha256-Snapshot des letzten Vault-Imports (Import-Guard, App-wins)');
        });

        // Baseline: was heute in der DB steht, gilt als „zuletzt importiert".
        DB::table('foodalchemist_knowledge_documents')
            ->whereNull('imported_hash')
            ->whereNotNull('source_path')
            ->update(['imported_hash' => DB::raw('content_hash')]);
    }

    public function down(): void
    {
        Schema::table('foodalchemist_knowledge_documents', function (Blueprint $table) {
            $table->dropColumn('imported_hash');
        });
    }
};
