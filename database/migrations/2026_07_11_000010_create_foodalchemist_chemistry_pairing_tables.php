<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chemie-/Pairing-Labor (26 Tabellen, von FoodBrain-Python gebaut, jetzt FA-nativ).
 * Auto-generiert aus der Sandbox-SQLite (Schema-Spiegel). Review vor Push an Martin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_anchor_ingredient_map', function (Blueprint $table) {
            $table->bigInteger('anchor_id')->nullable();
            $table->string('slug_de', 64)->nullable();
            $table->bigInteger('ingredient_id')->nullable();
            $table->string('label_en', 128)->nullable();
            $table->bigInteger('has_profile')->nullable();
            $table->bigInteger('n_key_components')->nullable();
            $table->string('match_method', 64)->nullable();
        });
        Schema::create('foodalchemist_anchor_taste_axis', function (Blueprint $table) {
            $table->bigInteger('anchor_id')->nullable();
            $table->string('axis', 32)->nullable();
            $table->double('weight')->nullable();
            $table->bigInteger('n_hits')->nullable();
            $table->bigInteger('n_edges')->nullable();
            $table->index(['anchor_id'], 'fa_anchor_taste_axis_anchor_id_ix');
        });
        Schema::create('foodalchemist_anchor_taste_vectors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('anchor_id')->nullable();
            $table->double('suess')->nullable();
            $table->double('salzig')->nullable();
            $table->double('sauer')->nullable();
            $table->double('bitter')->nullable();
            $table->double('umami')->nullable();
            $table->double('fettig')->nullable();
            $table->double('scharf')->nullable();
            $table->string('source', 32)->nullable();
            $table->double('ai_confidence')->nullable();
            $table->string('ai_reasoning', 32)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['anchor_id'], 'fa_anchor_taste_vectors_anchor_id_uq');
        });
        Schema::create('foodalchemist_aroma_descriptors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('type_id')->nullable();
            $table->string('name', 32)->nullable();
        });
        Schema::create('foodalchemist_aroma_types', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('type_key', 32)->nullable();
            $table->string('label_de', 32)->nullable();
            $table->unique(['type_key'], 'fa_aroma_types_type_key_uq');
        });
        Schema::create('foodalchemist_book_pairings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name_a', 128)->nullable();
            $table->string('name_b', 128)->nullable();
            $table->bigInteger('anchor_a_id')->nullable();
            $table->bigInteger('anchor_b_id')->nullable();
            $table->string('base_a', 64)->nullable();
            $table->string('base_b', 64)->nullable();
            $table->string('prep_a', 32)->nullable();
            $table->string('prep_b', 32)->nullable();
            $table->string('dec_a', 32)->nullable();
            $table->string('dec_b', 32)->nullable();
            $table->bigInteger('n_vorkommen')->nullable();
            $table->bigInteger('n_kapitel')->nullable();
            $table->string('source', 32)->nullable();
        });
        Schema::create('foodalchemist_chem_ingredients', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('label_en', 128)->nullable();
            $table->string('category', 64)->nullable();
            $table->string('food_group', 64)->nullable();
            $table->string('source', 32)->nullable();
            $table->bigInteger('legacy_ahn_id')->nullable();
            $table->bigInteger('legacy_foodb_id')->nullable();
            $table->index(['label_en'], 'fa_chem_ingredients_label_en_ix');
        });
        Schema::create('foodalchemist_flavor_descriptors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('foodb_flavor_id')->nullable();
            $table->string('name', 64)->nullable();
            $table->string('flavor_group', 32)->nullable();
            $table->string('category', 32)->nullable();
        });
        Schema::create('foodalchemist_flavordb_mol_props', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('molecule_id')->nullable();
            $table->text('taste')->nullable();
            $table->bigInteger('bitter')->nullable();
            $table->string('super_sweet', 32)->nullable();
            $table->string('odor', 512)->nullable();
            $table->string('flavor_profile', 512)->nullable();
            $table->string('functional_groups', 512)->nullable();
            $table->string('fema_flavor_profile', 512)->nullable();
            $table->string('source', 32)->nullable();
        });
        Schema::create('foodalchemist_ingredient_aroma_vector', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('ingredient_id')->nullable();
            $table->string('method', 32)->nullable();
            $table->bigInteger('n_molecules_typed')->nullable();
            $table->double('coverage_ratio')->nullable();
            $table->double('fruity')->nullable();
            $table->double('citrus')->nullable();
            $table->double('floral')->nullable();
            $table->double('green')->nullable();
            $table->double('herbal')->nullable();
            $table->double('vegetable')->nullable();
            $table->double('caramel')->nullable();
            $table->double('roasted')->nullable();
            $table->double('nutty')->nullable();
            $table->double('woody')->nullable();
            $table->double('spicy')->nullable();
            $table->double('cheesy')->nullable();
            $table->double('animal')->nullable();
            $table->double('chemical')->nullable();
        });
        Schema::create('foodalchemist_ingredient_flavordb_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('miskg_id', 32)->nullable();
            $table->string('ingredient', 128)->nullable();
            $table->bigInteger('flavordb_id')->nullable();
            $table->string('flavordb_name', 128)->nullable();
            $table->double('cosine_similarity')->nullable();
            $table->string('source', 32)->nullable();
            $table->index(['ingredient'], 'fa_ingredient_flavordb_map_ingredient_ix');
        });
        Schema::create('foodalchemist_ingredient_key_component', function (Blueprint $table) {
            $table->bigInteger('ingredient_id')->nullable();
            $table->bigInteger('component_id')->nullable();
            $table->bigInteger('n_molecules')->nullable();
            $table->index(['component_id'], 'fa_ingredient_key_component_component_id_ix');
            $table->index(['ingredient_id'], 'fa_ingredient_key_component_ingredient_id_ix');
        });
        Schema::create('foodalchemist_ingredient_molecule', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('ingredient_id')->nullable();
            $table->bigInteger('molecule_id')->nullable();
            $table->string('detection', 32)->nullable();
            $table->double('concentration')->nullable();
            $table->double('conc_min')->nullable();
            $table->double('conc_max')->nullable();
            $table->string('conc_unit', 32)->nullable();
            $table->string('source', 32)->nullable();
            $table->index(['ingredient_id', 'source'], 'fa_ingredient_molecule_ingredient_id_source_ix');
            $table->index(['source'], 'fa_ingredient_molecule_source_ix');
            $table->index(['molecule_id'], 'fa_ingredient_molecule_molecule_id_ix');
            $table->index(['ingredient_id'], 'fa_ingredient_molecule_ingredient_id_ix');
        });
        Schema::create('foodalchemist_ingredient_taste_axis', function (Blueprint $table) {
            $table->bigInteger('ingredient_id')->nullable();
            $table->string('axis', 32)->nullable();
            $table->double('weight')->nullable();
            $table->string('source', 32)->nullable();
        });
        Schema::create('foodalchemist_key_component_molecule', function (Blueprint $table) {
            $table->bigInteger('component_id')->nullable();
            $table->bigInteger('molecule_id')->nullable();
            $table->string('match_via', 32)->nullable();
        });
        Schema::create('foodalchemist_key_components', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('key', 32)->nullable();
            $table->string('family', 64)->nullable();
            $table->string('aroma_type', 32)->nullable();
            $table->string('character', 128)->nullable();
            $table->string('kind', 32)->nullable();
            $table->bigInteger('n_molecules')->nullable();
            $table->bigInteger('n_ingredients')->nullable();
            $table->unique(['key'], 'fa_key_components_key_uq');
        });
        Schema::create('foodalchemist_miskg_ingredients', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('miskg_id', 32)->nullable();
            $table->string('name', 255)->nullable();
            $table->string('source', 32)->nullable();
            $table->index(['name'], 'fa_miskg_ingredients_name_ix');
        });
        Schema::create('foodalchemist_molecule_descriptors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('molecule_id')->nullable();
            $table->bigInteger('flavor_descriptor_id')->nullable();
            $table->string('source', 32)->nullable();
            $table->string('citation', 512)->nullable();
            $table->index(['molecule_id'], 'fa_molecule_descriptors_molecule_id_ix');
        });
        Schema::create('foodalchemist_molecule_type_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('molecule_id')->nullable();
            $table->bigInteger('aroma_type_id')->nullable();
            $table->double('weight')->nullable();
            $table->string('method', 64)->nullable();
            $table->string('provenance', 512)->nullable();
            $table->index(['molecule_id'], 'fa_molecule_type_map_molecule_id_ix');
        });
        Schema::create('foodalchemist_molecules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('inchikey', 64)->nullable();
            $table->string('cas', 128)->nullable();
            $table->string('name', 512)->nullable();
            $table->text('smiles')->nullable();
            $table->double('mono_mass')->nullable();
            $table->string('chem_kingdom', 64)->nullable();
            $table->string('chem_superclass', 128)->nullable();
            $table->string('chem_class', 128)->nullable();
            $table->string('chem_subclass', 128)->nullable();
            $table->string('source', 32)->nullable();
            $table->bigInteger('legacy_ahn_id')->nullable();
            $table->bigInteger('legacy_foodb_id')->nullable();
            $table->string('foodb_public_id', 32)->nullable();
            $table->bigInteger('pubchem_id')->nullable();
            $table->index(['inchikey'], 'fa_molecules_inchikey_ix');
            $table->index(['cas'], 'fa_molecules_cas_ix');
            $table->index(['legacy_foodb_id'], 'fa_molecules_legacy_foodb_id_ix');
        });
        Schema::create('foodalchemist_pairing_computed', function (Blueprint $table) {
            $table->string('label_a', 128)->nullable();
            $table->string('label_b', 128)->nullable();
            $table->double('harmony')->nullable();
            $table->double('contrast')->nullable();
            $table->double('synergy')->nullable();
            $table->string('relation', 64)->nullable();
            $table->double('confidence')->nullable();
            $table->string('evidence_auto', 255)->nullable();
        });
        Schema::create('foodalchemist_prep_aroma_delta', function (Blueprint $table) {
            $table->bigInteger('prep_id');
            $table->bigInteger('aroma_type_id');
            $table->double('delta')->nullable();
            $table->primary(['prep_id', 'aroma_type_id'], 'fa_prep_aroma_delta_prep_id_aroma_type_id_uq');
        });
        Schema::create('foodalchemist_prep_taste_delta', function (Blueprint $table) {
            $table->bigInteger('prep_id');
            $table->bigInteger('taste_axis_id');
            $table->double('delta')->nullable();
            $table->primary(['prep_id', 'taste_axis_id'], 'fa_prep_taste_delta_prep_id_taste_axis_id_uq');
        });
        Schema::create('foodalchemist_preparations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('slug', 32)->nullable();
            $table->string('display_de', 32)->nullable();
            $table->string('family', 32)->nullable();
            $table->string('mechanism', 128)->nullable();
            $table->bigInteger('n_occurrences')->nullable();
            $table->unique(['slug'], 'fa_preparations_slug_uq');
        });
        Schema::create('foodalchemist_substitutions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('ingredient', 128)->nullable();
            $table->string('substitution', 255)->nullable();
            $table->string('ingredient_miskg_id', 32)->nullable();
            $table->string('substitution_miskg_id', 32)->nullable();
            $table->string('source', 32)->nullable();
            $table->index(['ingredient'], 'fa_substitutions_ingredient_ix');
        });
        Schema::create('foodalchemist_taste_axes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('axis', 32)->nullable();
            $table->string('triggers', 128)->nullable();
            $table->unique(['axis'], 'fa_taste_axes_axis_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_taste_axes');
        Schema::dropIfExists('foodalchemist_substitutions');
        Schema::dropIfExists('foodalchemist_preparations');
        Schema::dropIfExists('foodalchemist_prep_taste_delta');
        Schema::dropIfExists('foodalchemist_prep_aroma_delta');
        Schema::dropIfExists('foodalchemist_pairing_computed');
        Schema::dropIfExists('foodalchemist_molecules');
        Schema::dropIfExists('foodalchemist_molecule_type_map');
        Schema::dropIfExists('foodalchemist_molecule_descriptors');
        Schema::dropIfExists('foodalchemist_miskg_ingredients');
        Schema::dropIfExists('foodalchemist_key_components');
        Schema::dropIfExists('foodalchemist_key_component_molecule');
        Schema::dropIfExists('foodalchemist_ingredient_taste_axis');
        Schema::dropIfExists('foodalchemist_ingredient_molecule');
        Schema::dropIfExists('foodalchemist_ingredient_key_component');
        Schema::dropIfExists('foodalchemist_ingredient_flavordb_map');
        Schema::dropIfExists('foodalchemist_ingredient_aroma_vector');
        Schema::dropIfExists('foodalchemist_flavordb_mol_props');
        Schema::dropIfExists('foodalchemist_flavor_descriptors');
        Schema::dropIfExists('foodalchemist_chem_ingredients');
        Schema::dropIfExists('foodalchemist_book_pairings');
        Schema::dropIfExists('foodalchemist_aroma_types');
        Schema::dropIfExists('foodalchemist_aroma_descriptors');
        Schema::dropIfExists('foodalchemist_anchor_taste_vectors');
        Schema::dropIfExists('foodalchemist_anchor_taste_axis');
        Schema::dropIfExists('foodalchemist_anchor_ingredient_map');
    }
};
