<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 60 · P7c (Entscheidung Dominique 2026-10-06): Pairing-Chips am Rezept (`recipe_pairings`,
 * auf demo 838 KI-Zeilen) und KI-Anker am Rezept (`recipe_anchor_mappings`, 161 Zeilen) entfallen.
 * Ersetzt durch das Aromenprofil (`recipe_profile_anker`, abgeleitet aus den Zutaten) und die
 * Kombinationslogik. Alle Leser sind im selben Paket umgestellt.
 *
 * Sicherung (alle Zeilen + DDL, demo 2026-10-06):
 * COOKING JARVIS/12_DATA/fa_demo_archiv_pairing_2026-10-06/foodalchemist_<tabelle>.jsonl.gz
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('foodalchemist_recipe_pairings');
        Schema::dropIfExists('foodalchemist_recipe_anchor_mappings');
    }

    public function down(): void
    {
        // Bewusst leer: Wiederherstellung aus der Sicherung (siehe Docblock).
    }
};
