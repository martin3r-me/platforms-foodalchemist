<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 60 · P3: Rückbau des alten Anker-Systems und des Chemie-Ansatzes (Entscheidung Dominique
 * 2026-10-06). Keine Tabelle hat eingehende FKs; der Code, der sie las, ist im selben Paket
 * entfernt.
 *
 *  - Alte Anker-IDs (0 Treffer auf Inspire-Anker): anchor_taste_axis, book_pairings
 *  - Prozess-Anker (auf demo 0 Anker, 0 Zuordnungen): recipe_process_anchors
 *  - Leer: anchor_taste_vectors
 *  - Moleküle/Chemie (nur 11 % der Inspire-Anker erreichbar): 22 Tabellen
 *
 * Sicherung (alle Zeilen + DDL, demo, 2026-10-06):
 * COOKING JARVIS/12_DATA/fa_demo_archiv_pairing_2026-10-06/foodalchemist_<tabelle>.jsonl.gz
 * Ein down() stellt keine Daten her; zum Zurückholen die DDL aus der Sicherung nehmen.
 */
return new class extends Migration
{
    public const TABELLEN = [
        // alte Anker-IDs / Prozess-Anker / leer
        'foodalchemist_anchor_taste_axis',
        'foodalchemist_book_pairings',
        'foodalchemist_recipe_process_anchors',
        'foodalchemist_anchor_taste_vectors',
        // Moleküle / Chemie
        'foodalchemist_pairing_computed',
        'foodalchemist_molecules',
        'foodalchemist_molecule_descriptors',
        'foodalchemist_molecule_type_map',
        'foodalchemist_ingredient_molecule',
        'foodalchemist_ingredient_key_component',
        'foodalchemist_key_component_molecule',
        'foodalchemist_key_components',
        'foodalchemist_chem_ingredients',
        'foodalchemist_miskg_ingredients',
        'foodalchemist_ingredient_flavordb_map',
        'foodalchemist_flavordb_mol_props',
        'foodalchemist_ingredient_aroma_vector',
        'foodalchemist_ingredient_taste_axis',
        'foodalchemist_aroma_types',
        'foodalchemist_aroma_descriptors',
        'foodalchemist_flavor_descriptors',
        'foodalchemist_prep_aroma_delta',
        'foodalchemist_prep_taste_delta',
        'foodalchemist_preparations',
        'foodalchemist_taste_axes',
        'foodalchemist_anchor_ingredient_map',
    ];

    public function up(): void
    {
        foreach (self::TABELLEN as $t) {
            Schema::dropIfExists($t);
        }
    }

    public function down(): void
    {
        // Bewusst leer: Wiederherstellung aus der Sicherung (siehe Docblock).
    }
};
