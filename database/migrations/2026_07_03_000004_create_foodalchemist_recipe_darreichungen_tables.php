<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Umbau-Spec Darreichungen Phase 3: Spiegel von WaWi recipe_darreichungen
 * (+ Komponenten-Deltas, Stufe 2). Ein Gericht = ein kulinarischer Kern,
 * Darreichungen = dünne Varianten-Zeilen (Grammatur, Behälter, Regeneration,
 * EK/VK je Form). Grenzregel E5: Deltas dürfen nur reduzieren/weglassen —
 * nie neue Zutaten (Service-seitig erzwungen).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_recipe_darreichungen')) {
            Schema::create('foodalchemist_recipe_darreichungen', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('legacy_id')->nullable()->unique();
                $table->foreignId('recipe_id')->constrained('foodalchemist_recipes')->cascadeOnDelete();
                $table->foreignId('servierform_id')->constrained('foodalchemist_servierformen')->cascadeOnDelete();
                $table->boolean('ist_standard')->default(false);
                $table->decimal('menge_pro_einheit_g', 12, 3)->nullable();
                $table->foreignId('einheit_vocab_id')->nullable()->constrained('foodalchemist_vocab_einheiten')->nullOnDelete();
                $table->decimal('anzahl_einheiten', 12, 3)->nullable();
                $table->decimal('ek_portion', 12, 4)->nullable();          // berechnet (WaWi Recompute 206 Stufe 4)
                $table->foreignId('aufschlagsklasse_id')->nullable()->constrained('foodalchemist_markup_classes')->nullOnDelete();
                $table->decimal('vk_netto', 12, 2)->nullable();
                $table->decimal('vk_brutto', 12, 2)->nullable();
                $table->string('preis_modus', 12)->default('auto');        // auto | manuell
                $table->foreignId('behaelter_warm_vocab_id')->nullable()->constrained('foodalchemist_vocab_behaelter')->nullOnDelete();
                $table->foreignId('behaelter_kalt_vocab_id')->nullable()->constrained('foodalchemist_vocab_behaelter')->nullOnDelete();
                $table->integer('regeneration_temp_c')->nullable();
                $table->integer('regeneration_dauer_min')->nullable();
                $table->integer('regeneration_kerntemp_c')->nullable();
                $table->foreignId('regeneration_geraet_vocab_id')->nullable()->constrained('foodalchemist_vocab_regen_geraete')->nullOnDelete();
                $table->foreignId('servier_vehikel_vocab_id')->nullable()->constrained('foodalchemist_vocab_serviervehikel')->nullOnDelete();
                $table->integer('arbeitszeit_zuschlag_min')->nullable();
                $table->text('angebotstext_override')->nullable();          // NULL = erbt vom Kerngericht
                $table->text('note')->nullable();
                $table->string('created_via', 24)->nullable()->index();     // F12: fa_ui | mcp | NULL = Import/WaWi
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['recipe_id', 'servierform_id'], 'fa_recipe_darreichungen_recipe_form_unique');
            });

            // Genau EIN Standard pro Gericht (partieller Unique-Index; SQLite + Postgres).
            // MySQL kennt keine partiellen Indizes — dort erzwingt der Service die Invariante.
            if (in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
                DB::statement(
                    'CREATE UNIQUE INDEX fa_recipe_darreichungen_ein_standard'
                    .' ON foodalchemist_recipe_darreichungen (recipe_id)'
                    .' WHERE ist_standard = 1 AND deleted_at IS NULL'
                );
            }
        }

        if (! Schema::hasTable('foodalchemist_recipe_darreichung_deltas')) {
            Schema::create('foodalchemist_recipe_darreichung_deltas', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('legacy_id')->nullable()->unique();
                $table->foreignId('darreichung_id')->constrained('foodalchemist_recipe_darreichungen')->cascadeOnDelete();
                $table->foreignId('recipe_ingredient_id')->constrained('foodalchemist_recipe_ingredients')->cascadeOnDelete();
                $table->decimal('menge_override_g', 12, 3)->nullable();     // NULL = Menge unverändert
                $table->boolean('weggelassen')->default(false);
                $table->text('note')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['darreichung_id', 'recipe_ingredient_id'], 'fa_darreichung_deltas_darreichung_zutat_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_recipe_darreichung_deltas');
        Schema::dropIfExists('foodalchemist_recipe_darreichungen');
    }
};
