<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Cleanup nach der Migration der Food DNA ins zentrale Canvas-Modul (Dominique 2026-06-17,
 * „food dna migrieren"). Die getypten Vorgänger-Tabellen (kurzlebig, nur Sandbox) werden
 * durch foodalchemist_canvases/canvas_entries (canvas_type=food_dna) ersetzt.
 * dropIfExists = no-op, wo sie nie existierten (Martin-Deploy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('foodalchemist_concept_dna_overrides');
        Schema::dropIfExists('foodalchemist_food_dna');
    }

    public function down(): void
    {
        // Bewusst leer — Rückbau führt über die Canvas-Tabellen, nicht über die Legacy-Form.
    }
};
