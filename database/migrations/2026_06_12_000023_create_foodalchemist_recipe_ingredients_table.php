<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M4-01 / D-5 §2.2: Zutatenliste (Quelle recipe_ingredients, 9.590).
 * gp_id XOR referenced_recipe_id — XOR wird im Service erzwungen (07 §7:
 * kein Raw-CHECK). Tote Spalten NICHT migriert (GL-02 A-6): prozent_garverlust,
 * prozent_in_produkt, menge_in_g_computed. V-21: role + is_value_relevant von
 * Anfang an dabei (in der Alt-App tot, weil ohne UI).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique()->comment('Quell-PK recipe_ingredient_id');

            $table->foreignId('recipe_id')->constrained('foodalchemist_recipes')->cascadeOnDelete();
            $table->unsignedInteger('position')->comment('Quelle sort_order');

            $table->foreignId('gp_id')->nullable()->constrained('foodalchemist_gps')->nullOnDelete();
            $table->foreignId('referenced_recipe_id')->nullable()->constrained('foodalchemist_recipes')->nullOnDelete();

            $table->text('raw_text');
            $table->string('display_name', 512)->nullable()->comment('D-5 §2.2 — Anzeige-Name (neu, Backfill via V-03)');

            $table->decimal('quantity', 12, 4);
            $table->decimal('quantity_max', 12, 4)->nullable()->comment('Mengen-Bereich (§6.4: Mittelwert)');
            $table->foreignId('unit_vocab_id')->constrained('foodalchemist_vocab_units');

            $table->decimal('trimming_loss_pct', 5, 2)->nullable();
            $table->decimal('cooking_loss_pct', 5, 2)->nullable();
            $table->string('cooking_loss_source', 16)->nullable()->comment('manual|ki|auto (GL-07)');
            $table->decimal('cooking_loss_ai_confidence', 4, 3)->nullable();

            $table->boolean('is_optional')->default(false);
            $table->string('klammer_note')->nullable();
            $table->text('note')->nullable()->comment('Regelwerk §2: Verarbeitung wandert hierher');

            $table->string('match_method', 24)->nullable()
                ->comment('gp_v2_fk|recipe_ref|gemini_proposed|override_subrecipe|override_gp|manual|unmatched|ignored (GL-04 §2.3, Enum-Cast gegen A-10)');
            $table->decimal('match_confidence', 4, 3)->nullable();

            // V-21: von Anfang an mit Spalten + Pflege-UI
            $table->string('role', 16)->nullable()->comment('aroma_treiber|komponente|beilage|garnitur');
            $table->boolean('is_value_relevant')->default(false);
            $table->string('calc_mode', 16)->default('voll')->comment('voll|nur_naehrwerte|nur_allergene|keine');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['recipe_id', 'position']);
            $table->index('match_method');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_recipe_ingredients');
    }
};
