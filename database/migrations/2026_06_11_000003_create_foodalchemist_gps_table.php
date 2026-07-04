<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grundprodukte (GP) — Kern der kuratierten Welt (02_DATENMODELL §A.2, Quelle wawi_gp_v2, 7.774 Zeilen).
 *
 * - team_id NULL = global/BHG-kuratiert (D1); gp_key UNIQUE pro Scope (GL-12 I6: Slug byte-identisch zur Quelle!)
 * - Allergen-Block: 14 EU-Allergene, 4-Wert-Modell (GL-01), Spalten = Override-Layer über LA-Aggregation (V-08)
 * - Lineage-Trios (_quelle/_ai_confidence/_ai_begruendung) = GL-07-Pattern, Quellen-Enum manual|ki|auto
 * - *_legacy_id-Spalten: Referenzen auf noch nicht migrierte Welten (Supplier/LA/Food-Domain) —
 *   werden in Seed-Phase P2/P3 (07_MIGRATION_SEED) auf echte FKs umverdrahtet. Self-FKs sind echt.
 * - Datums-Spalten aus der Quelle werden beim Import nach UTC normalisiert (07 §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_gps', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('NULL = global (D1)');

            // ── Identität & Naming (GL-12)
            $table->string('gp_key')->comment('hauptzutat|processing|form — slugify() byte-identisch zur Alt-App (GL-12 I6)');
            $table->string('name')->index()->comment('§6-Schema: Produktname: Eigenschaft, Zustand, … (GL-12)');
            $table->string('main_ingredient_slug')->nullable()->index()->comment('NULL = Hygiene-Fall (26 Test-GPs im Seed, V-22-Flag) — Pflichtfeld ab GP-Editor (D-3)');
            $table->string('main_ingredient_display')->nullable();
            $table->string('processing')->nullable();
            $table->string('form')->nullable();

            // ── Klassifikation (§3/§9 GP-Regelwerk)
            $table->string('commodity_group_code', 8)->nullable()->index()->comment('FK-leicht auf lookup_warengruppen.code');
            $table->string('sub_category')->nullable();
            $table->string('condition', 16)->nullable()->comment('frisch|TK|trocken|konserviert (§9); WG 15 ausgenommen (Regelwerk-Rev. v3.3.3 geplant)');
            $table->string('bio', 16)->nullable();

            // ── Status & Kuratierung (GL-05/GL-07)
            $table->string('status', 16)->default('tentative')->index()->comment('approved|tentative|rejected|merged');
            $table->foreignId('merged_into_id')->nullable()->constrained('foodalchemist_gps')->nullOnDelete();
            $table->text('reviewer_note')->nullable();
            $table->dateTime('first_seen_at')->nullable();
            $table->dateTime('last_review_at')->nullable();
            $table->decimal('ai_confidence', 4, 3)->nullable();
            $table->text('ai_reasoning')->nullable();

            // ── Derivate (§11 GP-Regelwerk)
            $table->boolean('is_derivat')->default(false);
            $table->foreignId('derivat_von_gp_id')->nullable()->constrained('foodalchemist_gps')->nullOnDelete();
            $table->boolean('requires_la')->default(true);
            $table->boolean('is_platzhalter')->default(false);

            // ── LA-Welt (GL-03/GL-05) — Legacy-Refs bis Seed-Phase P2/P3
            $table->unsignedInteger('n_las_total')->default(0);
            $table->unsignedBigInteger('first_supplier_legacy_id')->nullable()->comment('Necta supplier_id — FK folgt P2');
            $table->unsignedBigInteger('lead_la_supplier_item_legacy_id')->nullable()->comment('FK folgt P3; danach V-27-Kette');

            // ── Kalkulations-Defaults (GL-02)
            $table->decimal('cooking_loss_default_pct', 5, 2)->nullable();
            $table->decimal('piece_default_g', 8, 2)->nullable();
            $table->string('piece_default_g_source', 16)->nullable()->comment('manual|ki|auto (GL-07)');
            $table->decimal('stk_default_g_ai_confidence', 4, 3)->nullable();
            $table->text('stk_default_g_ai_begruendung')->nullable();
            $table->foreignId('preferred_count_unit_id')->nullable()->constrained('foodalchemist_vocab_units')->nullOnDelete();

            // ── Allergen-Override-Layer: 14 EU-Allergene, 4-Wert (GL-01; NULL = kein Override → LA-Aggregation)
            foreach ([
                'glutenhaltiges_getreide', 'krebstiere', 'eier', 'fisch', 'erdnuesse', 'soja', 'milch',
                'schalenfruechte', 'sellerie', 'senf', 'sesam', 'schwefeldioxid', 'lupinen', 'weichtiere',
            ] as $allergen) {
                $table->string("allergen_{$allergen}", 16)->nullable();
            }
            $table->string('allergens_source', 16)->nullable()->comment('manual|ki|auto (GL-07)');
            $table->decimal('allergene_ai_confidence', 4, 3)->nullable();
            $table->dateTime('allergene_aggregiert_am')->nullable();

            // ── Eigenschafts-Tags (tri-state: NULL = unbewertet)
            foreach ([
                'is_vegan', 'is_vegetarian', 'is_halal', 'contains_pork', 'contains_beef',
                'is_organic', 'is_regional', 'is_grundnahrungsmittel', 'is_convenience',
                'is_lactose_free', 'is_gluten_free',
            ] as $tag) {
                $table->boolean("tag_{$tag}")->nullable();
            }
            $table->string('tag_source', 16)->nullable();
            $table->decimal('tag_ai_confidence', 4, 3)->nullable();
            $table->text('tag_ai_begruendung')->nullable();
            $table->dateTime('tag_aggregiert_am')->nullable();

            // ── Food-Domain (Wissens-Routing GL-13) — Legacy-Ref bis Knowledge-Import (⚠D4)
            $table->unsignedBigInteger('primary_food_domain_legacy_id')->nullable()->comment('FK auf vocab_food_domain folgt mit Knowledge-Import');
            $table->string('food_domain_source', 16)->nullable();
            $table->decimal('food_domain_ai_confidence', 4, 3)->nullable();
            $table->text('food_domain_ai_begruendung')->nullable();
            $table->dateTime('food_domain_aggregiert_am')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'gp_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_gps');
    }
};
