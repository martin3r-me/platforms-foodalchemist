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
 *
 * Idempotent: `hasTable` + `hasForeign` schützen vor halb-gelaufener Migration
 * (MySQL hat kein transaktionales DDL — die ersten kurzen FKs landen per ALTER,
 * dann scheitert der erste FK-Name >64 Zeichen und hinterlässt eine partielle Tabelle).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_recipe_presentations')) {
            Schema::create('foodalchemist_recipe_presentations', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('legacy_id')->nullable()->unique();
                $table->foreignId('recipe_id')->constrained('foodalchemist_recipes')->cascadeOnDelete();
                $table->foreignId('serving_form_id')->constrained('foodalchemist_serving_forms')->cascadeOnDelete();
                $table->boolean('is_standard')->default(false);
                $table->decimal('quantity_pro_unit_g', 12, 3)->nullable();
                $table->foreignId('unit_vocab_id')->nullable()->constrained('foodalchemist_vocab_units')->nullOnDelete();
                $table->decimal('unit_count', 12, 3)->nullable();
                $table->decimal('ek_portion', 12, 4)->nullable();          // berechnet (WaWi Recompute 206 Stufe 4)
                $table->foreignId('markup_class_id')->nullable()->constrained('foodalchemist_markup_classes')->nullOnDelete();
                $table->decimal('sales_net', 12, 2)->nullable();
                $table->decimal('sales_gross', 12, 2)->nullable();
                $table->string('preis_modus', 12)->default('auto');        // auto | manuell
                // MySQL-Identifier-Limit (64): FK-Namen für lange Spalten explizit gekürzt.
                $table->foreignId('container_warm_vocab_id')->nullable();
                $table->foreign('container_warm_vocab_id', 'fa_recipe_darreichungen_behaelter_warm_fk')
                    ->references('id')->on('foodalchemist_vocab_containers')->nullOnDelete();
                $table->foreignId('container_cold_vocab_id')->nullable();
                $table->foreign('container_cold_vocab_id', 'fa_recipe_darreichungen_behaelter_kalt_fk')
                    ->references('id')->on('foodalchemist_vocab_containers')->nullOnDelete();
                $table->integer('regeneration_temp_c')->nullable();
                $table->integer('regeneration_duration_min')->nullable();
                $table->integer('regeneration_core_temp_c')->nullable();
                $table->foreignId('regeneration_device_vocab_id')->nullable();
                $table->foreign('regeneration_device_vocab_id', 'fa_recipe_darreichungen_regen_geraet_fk')
                    ->references('id')->on('foodalchemist_vocab_regeneration_devices')->nullOnDelete();
                $table->foreignId('serving_vehicle_vocab_id')->nullable();
                $table->foreign('serving_vehicle_vocab_id', 'fa_recipe_darreichungen_servier_vehikel_fk')
                    ->references('id')->on('foodalchemist_vocab_serving_vehicles')->nullOnDelete();
                $table->integer('work_time_surcharge_min')->nullable();
                $table->text('offer_text_override')->nullable();          // NULL = erbt vom Kerngericht
                $table->text('note')->nullable();
                $table->string('created_via', 24)->nullable()->index();     // F12: fa_ui | mcp | NULL = Import/WaWi
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['recipe_id', 'serving_form_id'], 'fa_recipe_darreichungen_recipe_form_unique');
            });

            // Genau EIN Standard pro Gericht (partieller Unique-Index; SQLite + Postgres).
            // MySQL kennt keine partiellen Indizes — dort erzwingt der Service die Invariante.
            if (in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
                DB::statement(
                    'CREATE UNIQUE INDEX fa_recipe_darreichungen_ein_standard'
                    .' ON foodalchemist_recipe_presentations (recipe_id)'
                    .' WHERE is_standard = 1 AND deleted_at IS NULL'
                );
            }
        }

        // Recovery: failed Prod-Deploy hinterließ Tabelle mit den ersten kurzen FKs,
        // scheiterte bei `behaelter_warm_vocab_id_foreign` (66 chars). Fehlende FKs nachziehen.
        $this->ensureForeign('foodalchemist_recipe_presentations', 'fa_recipe_darreichungen_behaelter_warm_fk', function (Blueprint $t) {
            $t->foreign('container_warm_vocab_id', 'fa_recipe_darreichungen_behaelter_warm_fk')
                ->references('id')->on('foodalchemist_vocab_containers')->nullOnDelete();
        });
        $this->ensureForeign('foodalchemist_recipe_presentations', 'fa_recipe_darreichungen_behaelter_kalt_fk', function (Blueprint $t) {
            $t->foreign('container_cold_vocab_id', 'fa_recipe_darreichungen_behaelter_kalt_fk')
                ->references('id')->on('foodalchemist_vocab_containers')->nullOnDelete();
        });
        $this->ensureForeign('foodalchemist_recipe_presentations', 'fa_recipe_darreichungen_regen_geraet_fk', function (Blueprint $t) {
            $t->foreign('regeneration_device_vocab_id', 'fa_recipe_darreichungen_regen_geraet_fk')
                ->references('id')->on('foodalchemist_vocab_regeneration_devices')->nullOnDelete();
        });
        $this->ensureForeign('foodalchemist_recipe_presentations', 'fa_recipe_darreichungen_servier_vehikel_fk', function (Blueprint $t) {
            $t->foreign('serving_vehicle_vocab_id', 'fa_recipe_darreichungen_servier_vehikel_fk')
                ->references('id')->on('foodalchemist_vocab_serving_vehicles')->nullOnDelete();
        });

        if (! Schema::hasTable('foodalchemist_recipe_presentation_deltas')) {
            Schema::create('foodalchemist_recipe_presentation_deltas', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('legacy_id')->nullable()->unique();
                $table->foreignId('presentation_id')->constrained('foodalchemist_recipe_presentations')->cascadeOnDelete();
                // MySQL-Identifier-Limit (64): expliziter kurzer FK-Name.
                $table->foreignId('recipe_ingredient_id');
                $table->foreign('recipe_ingredient_id', 'fa_darreichung_deltas_recipe_zutat_fk')
                    ->references('id')->on('foodalchemist_recipe_ingredients')->cascadeOnDelete();
                $table->decimal('quantity_override_g', 12, 3)->nullable();     // NULL = Menge unverändert
                $table->boolean('weggelassen')->default(false);
                $table->text('note')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['presentation_id', 'recipe_ingredient_id'], 'fa_darreichung_deltas_darreichung_zutat_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_recipe_presentation_deltas');
        Schema::dropIfExists('foodalchemist_recipe_presentations');
    }

    private function ensureForeign(string $table, string $name, Closure $add): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        $exists = collect(Schema::getForeignKeys($table))->pluck('name')->contains($name);
        if (! $exists) {
            Schema::table($table, $add);
        }
    }
};
