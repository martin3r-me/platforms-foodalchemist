<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M4-01 / D-5 §2.1: EIN Rezept-Modell, zwei Service-Sichten (basis()/verkauf()
 * via ist_verkaufsrezept) — Quelle `recipes` (1.407). CHECKs werden PHP-Enums
 * (07 §7: kein Raw-SQL, Index-Namen auto). team_id NOT NULL (⚠D1: Rezepte sind
 * IMMER team-eigen; BHG-Bibliothek geht als Snapshot-Kopie an Teams, ⚠D2).
 *
 * Bewusst NICHT migriert (GL-02 A-6, tote Spalten): prozent_garverlust,
 * prozent_in_produkt, menge_in_g_computed (Zutaten-Seite).
 * Neu (GL-02 A-3): yield_kg_manual — Vorrang via COALESCE im Recompute.
 * Taxonomie v2 + Vocab-FKs ohne Zieltabelle (E5/V-20 offen) als *_legacy_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_recipes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('legacy_id')->nullable()->unique()->comment('Quell-PK recipes.recipe_id');

            // ── Identität (Regelwerk BR §1)
            $table->string('recipe_key')->index();
            $table->string('name');
            $table->string('herkunft')->nullable();
            $table->foreignId('kategorie_id')->nullable()->constrained('foodalchemist_recipe_categories')->nullOnDelete();
            $table->unsignedBigInteger('kat_v2_legacy_id')->nullable()->comment('Taxonomie v2 (E5 offen) — FK folgt');
            $table->unsignedBigInteger('klasse_v2_legacy_id')->nullable();
            $table->boolean('ist_verkaufsrezept')->default(false)->index();
            $table->string('status', 16)->default('draft')->index()->comment('stub|draft|review|approved|deprecated');

            // ── Yield & Arbeit (GL-02)
            $table->decimal('yield_kg', 10, 3)->nullable();
            $table->decimal('yield_kg_manual', 10, 3)->nullable()->comment('GL-02 A-3: manueller Vorrang (COALESCE)');
            $table->unsignedInteger('arbeitszeit_min')->nullable();

            // ── Inhalt
            $table->text('beschreibung')->nullable()->comment('Quelle ki_beschreibung (§8-Stil)');
            $table->string('temperatur', 32)->nullable();
            $table->string('funktion', 64)->nullable();
            $table->text('zubereitung')->nullable();
            $table->text('notizen')->nullable();
            $table->text('notizen_manual')->nullable()->comment('Regelwerk §9.1 — überlebt jede Generierung');
            $table->string('geschmacksrichtung', 16)->nullable()->comment('suess|herzhaft|neutral');
            $table->string('geschmacksrichtung_quelle', 16)->nullable();
            $table->decimal('geschmacksrichtung_ai_confidence', 4, 3)->nullable();
            $table->string('fertigungstiefe', 16)->nullable()->comment('from_scratch|teilfertig|convenience');
            $table->unsignedBigInteger('sub_rezept_typ_legacy_id')->nullable()->comment('vocab_sub_rezept_typ — Tabelle folgt (V-20)');
            $table->string('sub_rezept_typ_quelle', 16)->nullable();
            $table->decimal('sub_rezept_typ_ai_confidence', 4, 3)->nullable();
            $table->text('sub_rezept_typ_ai_begruendung')->nullable();
            $table->text('context_hooks_json')->nullable();

            // ── Allergene (GL-01 — NUR RecipeRecomputeService schreibt)
            foreach ([
                'glutenhaltiges_getreide', 'krebstiere', 'eier', 'fisch', 'erdnuesse', 'soja', 'milch',
                'schalenfruechte', 'sellerie', 'senf', 'sesam', 'schwefeldioxid', 'lupinen', 'weichtiere',
            ] as $allergen) {
                $table->string("allergen_{$allergen}", 16)->default('unbekannt');
            }
            $table->string('allergene_konfidenz', 16)->default('unknown')->comment('high|medium|low|unknown (GL-01 §4.4)');
            $table->dateTime('allergene_aggregiert_am')->nullable();

            // ── Zusatzstoffe (GL-09, Roh-Domäne {0,1,3,NULL})
            foreach ([
                'with_dye', 'with_preservative', 'with_antioxidant', 'with_flavour_enhancer',
                'sulphurated', 'blackened', 'waxed', 'with_phosphate', 'with_sweetener',
                'contains_phenylalanine', 'excessive_consumption_laxative', 'packaged_modified_atmosphere',
                'caffeinated', 'contains_milk_protein', 'contains_quinine', 'taurine_containing',
                'can_impair_attention_children', 'with_type_sugar_sweetener',
            ] as $stoff) {
                $table->unsignedTinyInteger("zusatz_{$stoff}")->nullable();
            }
            $table->dateTime('zusatz_aggregiert_am')->nullable();

            // ── Zähler & Versionierung
            $table->unsignedInteger('n_zutaten_total')->default(0);
            $table->unsignedInteger('n_zutaten_ungemappt')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->string('last_modified_by', 64)->nullable();

            // ── EK-Kaskade (GL-02)
            $table->decimal('ek_total_eur', 12, 4)->nullable();
            $table->decimal('ek_per_kg_eur', 12, 4)->nullable();
            $table->unsignedInteger('ek_n_ingredients_priced')->nullable();
            $table->unsignedInteger('ek_n_ingredients_total')->nullable();

            // ── Nährwerte (GL-08)
            $table->decimal('nutri_kcal_per_100g', 8, 1)->nullable();
            $table->decimal('nutri_protein_g_per_100g', 8, 2)->nullable();
            $table->decimal('nutri_fat_g_per_100g', 8, 2)->nullable();
            $table->decimal('nutri_carbs_g_per_100g', 8, 2)->nullable();
            $table->decimal('nutri_salt_g_per_100g', 8, 3)->nullable();
            $table->string('nutri_konfidenz', 16)->nullable();
            $table->unsignedInteger('nutri_n_ingredients_mapped')->nullable();
            $table->unsignedInteger('nutri_n_ingredients_total')->nullable();
            $table->dateTime('nutri_aggregiert_am')->nullable();

            // ── Spec-Flags (Diät/Herkunft-Aggregation)
            $table->decimal('spec_bio_pct', 5, 1)->nullable();
            $table->decimal('spec_regional_de_pct', 5, 1)->nullable();
            foreach (['is_vegan', 'is_vegetarian', 'is_halal', 'contains_pork', 'contains_beef', 'is_gluten_free', 'is_lactose_free'] as $spec) {
                $table->boolean("spec_{$spec}")->nullable();
            }
            $table->string('spec_konfidenz', 16)->nullable();
            $table->unsignedInteger('spec_n_mapped')->nullable();
            $table->unsignedInteger('spec_n_total')->nullable();
            $table->dateTime('spec_aggregiert_am')->nullable();

            // ── KI-Kuratierung (generisches Trio wie gps)
            $table->decimal('ai_confidence', 4, 3)->nullable();
            $table->text('ai_begruendung')->nullable();

            // ── VK-Block (D-6 §2 — UI folgt M6; Import braucht die Spalten jetzt)
            $table->unsignedBigInteger('aufschlagsklasse_legacy_id')->nullable();
            $table->unsignedBigInteger('speisen_klasse_legacy_id')->nullable();
            $table->decimal('vk_netto', 10, 2)->nullable();
            $table->decimal('vk_brutto', 10, 2)->nullable();
            $table->decimal('mwst_satz', 5, 2)->nullable();
            $table->unsignedSmallInteger('regeneration_temp_c')->nullable();
            $table->unsignedSmallInteger('regeneration_dauer_min')->nullable();
            $table->unsignedSmallInteger('regeneration_kerntemp_c')->nullable();
            $table->foreignId('vk_einheit_vocab_id')->nullable()->constrained('foodalchemist_vocab_einheiten')->nullOnDelete();
            $table->decimal('vk_menge_pro_einheit_g', 10, 2)->nullable();
            $table->decimal('vk_anzahl_einheiten', 10, 2)->nullable();
            $table->unsignedBigInteger('behaelter_warm_legacy_id')->nullable();
            $table->unsignedBigInteger('behaelter_kalt_legacy_id')->nullable();
            $table->unsignedBigInteger('regeneration_geraet_legacy_id')->nullable();
            $table->unsignedBigInteger('servier_vehikel_legacy_id')->nullable();
            $table->text('marketing_text')->nullable();
            $table->string('marketing_text_quelle', 16)->nullable();
            $table->decimal('marketing_text_ai_confidence', 4, 3)->nullable();
            $table->string('vk_wording_standard')->nullable();
            $table->boolean('is_template')->default(false);
            $table->foreignId('instantiated_from_recipe_id')->nullable()->constrained('foodalchemist_recipes')->nullOnDelete();

            // ── Audit/Lineage (Quelle Excel/PDF-Import)
            $table->unsignedInteger('excel_source_row')->nullable();
            $table->text('excel_raw_zutaten')->nullable();
            $table->text('excel_raw_zubereitung')->nullable();
            $table->unsignedInteger('pdf_page')->nullable();
            $table->text('pdf_raw_text')->nullable();
            $table->boolean('is_split_result')->default(false);
            $table->boolean('is_user_stub')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'recipe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_recipes');
    }
};
