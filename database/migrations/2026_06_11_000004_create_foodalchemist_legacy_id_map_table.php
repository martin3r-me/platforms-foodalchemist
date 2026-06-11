<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ID-Mapping Alt-SQLite → Plattform (07_MIGRATION_SEED §1).
 *
 * Macht den Import idempotent (Upsert per source_table+source_id), erlaubt FK-Umverdrahtung
 * über Phasen hinweg und ist die Referenz für Diff-Reports (z.B. Lead-LA-Drift GL-03 A-2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_legacy_id_map', function (Blueprint $table) {
            $table->id();
            $table->string('source_table', 64);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('target_id');
            $table->uuid('target_uuid');
            $table->timestamps();

            $table->unique(['source_table', 'source_id']);
            $table->index(['source_table', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_legacy_id_map');
    }
};
