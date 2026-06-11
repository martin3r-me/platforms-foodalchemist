<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M3-11: Ergebnis-Queue des Bulk-Match (GL-04 v1) — Vorschläge LA→GP, tentative
 * bis zur Review-Entscheidung (Übernehmen schreibt erst dann structure.gp_id).
 * Index-Namen auto-generiert (07 §7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_match_proposals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('supplier_item_id')->constrained('foodalchemist_supplier_items')->cascadeOnDelete();
            $table->foreignId('gp_id')->constrained('foodalchemist_gps')->cascadeOnDelete();
            $table->decimal('score', 5, 4);
            $table->string('band', 16)->comment('exact|fuzzy_high|fuzzy_low (GL-04 §4.1)');
            $table->string('methode', 24)->comment('exact_ean|exact_artno|fuzzy_name');
            $table->string('status', 16)->default('offen')->index()->comment('offen|akzeptiert|verworfen');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['supplier_item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_match_proposals');
    }
};
