<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M6-01 / D-6 §2: Verkaufslayer — Stammdaten (Aufschlagsklassen, Speisen-
 * Taxonomie, Schreibstile, Container-Vokabulare), Verwendungsnachweise,
 * Multi-Komponenten-Regeneration (V-19) + echte FK-Spalten am Rezept
 * (die *_legacy_id-Rohwerte aus M4-01 bleiben Import-Quelle).
 *
 * 07 §7: keine CHECK-Constraints (formula_type/diaetform als VARCHAR, Enum im
 * PHP-Layer), Index-Namen automatisch, engine-agnostisch. ⚠D1: Stammdaten
 * global = team_id NULL; recipe_customer_names ist team-eigen (NOT NULL im
 * Service erzwungen, Spalte nullable wegen importBulk-Symmetrie).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_markup_classes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->string('code', 16);
            $table->string('label');
            $table->decimal('raw_markup_pct', 7, 2)->default(0);
            $table->decimal('bedienung_pct', 7, 2)->default(0);
            $table->decimal('profit_pct', 7, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(19);
            $table->string('formula_type', 24)->default('aufschlag');   // aufschlag | deckungsbeitrag (W-1)
            $table->text('note')->nullable();
            $table->boolean('is_inactive')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'code']);
        });

        Schema::create('foodalchemist_dish_main_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->string('code', 16);                               // Pipe-Naming-Präfix (§4.4)
            $table->string('label');
            $table->integer('sort_order')->default(100);
            $table->boolean('is_inactive')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'code']);
        });

        Schema::create('foodalchemist_dish_classes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->foreignId('dish_main_group_id')->nullable()->constrained('foodalchemist_dish_main_groups')->nullOnDelete();
            $table->string('code', 32);
            $table->string('label');
            $table->foreignId('default_markup_class_id')->nullable()->constrained('foodalchemist_markup_classes')->nullOnDelete();
            $table->string('diaetform', 16)->default('neutral');      // fleisch|fisch|vegi|vegan|neutral|allergie
            $table->boolean('is_vegi')->default(false);
            $table->boolean('is_vegan')->default(false);
            $table->boolean('is_halal')->default(false);
            $table->boolean('is_koscher')->default(false);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'code']);
        });

        Schema::create('foodalchemist_writing_styles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->string('slug');
            $table->string('name');
            $table->text('sprach_duktus');                            // Prompt-Material (GL-06-Feld-Hülle)
            $table->text('beispiele_md')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_inactive')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'slug']);
        });

        // Container-Vokabulare (einheitliches D-1-Muster; Behälter mit kapazitaet_kg)
        foreach (['foodalchemist_vocab_containers' => true, 'foodalchemist_vocab_regeneration_devices' => false, 'foodalchemist_vocab_serving_vehicles' => false] as $name => $mitKapazitaet) {
            Schema::create($name, function (Blueprint $table) use ($mitKapazitaet) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->unsignedBigInteger('legacy_id')->nullable()->unique();
                $table->string('slug');
                $table->string('name');
                $table->string('gruppe')->nullable();
                if ($mitKapazitaet) {
                    $table->decimal('kapazitaet_kg', 8, 3)->nullable();
                }
                $table->integer('sort_order')->default(100);
                $table->boolean('is_inactive')->default(false);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['team_id', 'slug']);
            });
        }

        // Verwendungsnachweise: Kunde × Marketing-Name pro VK-Rezept (team-eigen)
        Schema::create('foodalchemist_recipe_customer_names', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            $table->foreignId('recipe_id')->constrained('foodalchemist_recipes')->cascadeOnDelete();
            $table->string('customer_name');
            $table->string('marketing_name');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['recipe_id', 'customer_name'], 'fa_recipe_cust_names_recipe_cust_unique');
        });

        // V-19: Multi-Komponenten-Regeneration (ersetzt das Ein-Programm-Skalar-Modell;
        // die M4-01-Skalarspalten bleiben als Import-Quelle stehen und werden zur
        // »Gesamt«-Zeile migriert — UI/Services lesen NUR diese Tabelle)
        Schema::create('foodalchemist_recipe_regenerations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('recipe_id')->constrained('foodalchemist_recipes')->cascadeOnDelete();
            $table->string('component_label');
            $table->foreignId('ingredient_id')->nullable()->constrained('foodalchemist_recipe_ingredients')->nullOnDelete();
            $table->foreignId('device_vocab_id')->nullable()->constrained('foodalchemist_vocab_regeneration_devices')->nullOnDelete();
            $table->integer('temp_c')->nullable();
            $table->integer('duration_min')->nullable();
            $table->integer('core_temp_c')->nullable();
            $table->text('note')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('source', 16)->nullable();                 // Lineage-Trio zeilenbasiert (GL-07 §3)
            $table->decimal('ai_confidence', 4, 3)->nullable();
            $table->text('ai_reasoning')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Echte FK-Spalten + fehlende Lineage am Rezept (Rohwerte *_legacy_id aus M4-01)
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->foreignId('dish_class_id')->nullable()->constrained('foodalchemist_dish_classes')->nullOnDelete();
            $table->string('dish_class_source', 16)->nullable();  // Lineage der KI-Klassifikation (GL-07)
            $table->decimal('dish_class_ai_confidence', 4, 3)->nullable();
            $table->text('dish_class_ai_reasoning')->nullable();
            $table->foreignId('markup_class_id')->nullable()->constrained('foodalchemist_markup_classes')->nullOnDelete();
            $table->foreignId('container_warm_vocab_id')->nullable()->constrained('foodalchemist_vocab_containers')->nullOnDelete();
            $table->integer('container_warm_count')->nullable();
            $table->foreignId('container_cold_vocab_id')->nullable()->constrained('foodalchemist_vocab_containers')->nullOnDelete();
            $table->integer('container_cold_count')->nullable();
            $table->foreignId('serving_vehicle_vocab_id')->nullable()->constrained('foodalchemist_vocab_serving_vehicles')->nullOnDelete();
            $table->string('sales_wording_source', 16)->nullable();      // Lineage sales_wording_standard (D-6 §2.1)
            $table->decimal('sales_wording_ai_confidence', 4, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dish_class_id');
            $table->dropConstrainedForeignId('markup_class_id');
            $table->dropConstrainedForeignId('container_warm_vocab_id');
            $table->dropConstrainedForeignId('container_cold_vocab_id');
            $table->dropConstrainedForeignId('serving_vehicle_vocab_id');
            $table->dropColumn([
                'dish_class_source', 'dish_class_ai_confidence', 'dish_class_ai_reasoning',
                'container_warm_count', 'container_cold_count', 'sales_wording_source', 'sales_wording_ai_confidence',
            ]);
        });
        Schema::dropIfExists('foodalchemist_recipe_regenerations');
        Schema::dropIfExists('foodalchemist_recipe_customer_names');
        Schema::dropIfExists('foodalchemist_vocab_serving_vehicles');
        Schema::dropIfExists('foodalchemist_vocab_regeneration_devices');
        Schema::dropIfExists('foodalchemist_vocab_containers');
        Schema::dropIfExists('foodalchemist_writing_styles');
        Schema::dropIfExists('foodalchemist_dish_classes');
        Schema::dropIfExists('foodalchemist_dish_main_groups');
        Schema::dropIfExists('foodalchemist_markup_classes');
    }
};
