<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M4-03 / GL-02 T1 Zeile 3: Stückgewichte je GP×Einheit (Knoblauch „Zehe" 5 g
 * vs. „Knolle" 40 g) — Quelle wawi_gp_count_unit_defaults (19). Ergänzt
 * gps.stk_default_g (T1 Zeile 4); KEINE Namens-Tabelle (GL-02 A-7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_gp_count_unit_defaults', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('NULL = global (D1)');
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->foreignId('gp_id')->constrained('foodalchemist_gps')->cascadeOnDelete();
            $table->foreignId('einheit_vocab_id')->constrained('foodalchemist_vocab_einheiten')->cascadeOnDelete();
            $table->decimal('default_g', 8, 2);
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('quelle', 16)->nullable()->comment('manual|ai_inferred (GL-07)');
            $table->decimal('ai_confidence', 4, 3)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['gp_id', 'einheit_vocab_id'], 'fa_gp_count_defaults_gp_einheit_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_gp_count_unit_defaults');
    }
};
