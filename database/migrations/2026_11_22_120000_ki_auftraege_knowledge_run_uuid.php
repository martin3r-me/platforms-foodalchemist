<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #1002: `foodalchemist_knowledge_runs.id` ist eine UUID — `ki_auftraege.knowledge_run_id` war ein BIGINT (Migration 2026_11_20_100000).
 * SQLite nimmt das still, MySQL (strict) lehnt es ab („Data truncated for column"): jeder externe Auftrag, der unter einem
 * Wissens-Lauf entsteht (Rezept-Anreicherung, Generator), ließe sich dort nicht anlegen. Die Spalte wird zur UUID, wie in
 * `foodalchemist_ai_call_log.knowledge_run_id` und `foodalchemist_recipes.knowledge_run_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Bestandswerte waren BIGINT-Zeilennummern ohne Bezug zu einem Lauf: nicht in UUIDs überführbar → leeren
        DB::table('foodalchemist_ki_auftraege')->update(['knowledge_run_id' => null]);
        Schema::table('foodalchemist_ki_auftraege', function (Blueprint $table) {
            $table->uuid('knowledge_run_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('foodalchemist_ki_auftraege')->update(['knowledge_run_id' => null]);
        Schema::table('foodalchemist_ki_auftraege', function (Blueprint $table) {
            $table->unsignedBigInteger('knowledge_run_id')->nullable()->change();
        });
    }
};
