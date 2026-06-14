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
 * 07 §7: keine CHECK-Constraints (formel_typ/diaetform als VARCHAR, Enum im
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
            $table->string('bezeichnung');
            $table->decimal('rohaufschlag_pct', 7, 2)->default(0);
            $table->decimal('bedienung_pct', 7, 2)->default(0);
            $table->decimal('profit_pct', 7, 2)->default(0);
            $table->decimal('mwst_satz', 5, 2)->default(19);
            $table->string('formel_typ', 24)->default('aufschlag');   // aufschlag | deckungsbeitrag (W-1)
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
            $table->string('bezeichnung');
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
            $table->string('bezeichnung');
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
            $table->text('beschreibung')->nullable();
            $table->boolean('is_inactive')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'slug']);
        });

        // Container-Vokabulare (einheitliches D-1-Muster; Behälter mit kapazitaet_kg)
        foreach (['foodalchemist_vocab_behaelter' => true, 'foodalchemist_vocab_regen_geraete' => false, 'foodalchemist_vocab_serviervehikel' => false] as $name => $mitKapazitaet) {
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
            $table->string('komponente_label');
            $table->foreignId('ingredient_id')->nullable()->constrained('foodalchemist_recipe_ingredients')->nullOnDelete();
            $table->foreignId('geraet_vocab_id')->nullable()->constrained('foodalchemist_vocab_regen_geraete')->nullOnDelete();
            $table->integer('temp_c')->nullable();
            $table->integer('dauer_min')->nullable();
            $table->integer('kerntemp_c')->nullable();
            $table->text('hinweis')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('quelle', 16)->nullable();                 // Lineage-Trio zeilenbasiert (GL-07 §3)
            $table->decimal('ai_confidence', 4, 3)->nullable();
            $table->text('ai_begruendung')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Echte FK-Spalten + fehlende Lineage am Rezept (Rohwerte *_legacy_id aus M4-01)
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->foreignId('speisen_klasse_id')->nullable()->constrained('foodalchemist_dish_classes')->nullOnDelete();
            $table->string('speisen_klasse_quelle', 16)->nullable();  // Lineage der KI-Klassifikation (GL-07)
            $table->decimal('speisen_klasse_ai_confidence', 4, 3)->nullable();
            $table->text('speisen_klasse_ai_begruendung')->nullable();
            $table->foreignId('aufschlagsklasse_id')->nullable()->constrained('foodalchemist_markup_classes')->nullOnDelete();
            $table->foreignId('behaelter_warm_vocab_id')->nullable()->constrained('foodalchemist_vocab_behaelter')->nullOnDelete();
            $table->integer('behaelter_warm_anzahl')->nullable();
            $table->foreignId('behaelter_kalt_vocab_id')->nullable()->constrained('foodalchemist_vocab_behaelter')->nullOnDelete();
            $table->integer('behaelter_kalt_anzahl')->nullable();
            $table->foreignId('servier_vehikel_vocab_id')->nullable()->constrained('foodalchemist_vocab_serviervehikel')->nullOnDelete();
            $table->string('vk_wording_quelle', 16)->nullable();      // Lineage vk_wording_standard (D-6 §2.1)
            $table->decimal('vk_wording_ai_confidence', 4, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('speisen_klasse_id');
            $table->dropConstrainedForeignId('aufschlagsklasse_id');
            $table->dropConstrainedForeignId('behaelter_warm_vocab_id');
            $table->dropConstrainedForeignId('behaelter_kalt_vocab_id');
            $table->dropConstrainedForeignId('servier_vehikel_vocab_id');
            $table->dropColumn([
                'speisen_klasse_quelle', 'speisen_klasse_ai_confidence', 'speisen_klasse_ai_begruendung',
                'behaelter_warm_anzahl', 'behaelter_kalt_anzahl', 'vk_wording_quelle', 'vk_wording_ai_confidence',
            ]);
        });
        Schema::dropIfExists('foodalchemist_recipe_regenerations');
        Schema::dropIfExists('foodalchemist_recipe_customer_names');
        Schema::dropIfExists('foodalchemist_vocab_serviervehikel');
        Schema::dropIfExists('foodalchemist_vocab_regen_geraete');
        Schema::dropIfExists('foodalchemist_vocab_behaelter');
        Schema::dropIfExists('foodalchemist_writing_styles');
        Schema::dropIfExists('foodalchemist_dish_classes');
        Schema::dropIfExists('foodalchemist_dish_main_groups');
        Schema::dropIfExists('foodalchemist_markup_classes');
    }
};
