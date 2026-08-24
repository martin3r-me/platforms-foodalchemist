<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conceptor-Kaskade Phase 1 (2026-08-24): Paket ⇄ Concept-Merge — Fundament.
 *
 * - concepts.kind: unterscheidet die Assembly-Ebene im EINEN Modell.
 *   'concept' (Default, Bestand) | 'paket' (wiederverwendbares Bündel mit eigenem Preis).
 *   (Format bleibt Phase-2/3 eine eigene Ebene — siehe _Spec_Conceptor_Kaskade.md.)
 * - concept_slots.embedded_concept_id: ein Concept-Slot vom type='paket' referenziert nach
 *   dem Merge die kind=paket-concepts.id (ersetzt schrittweise das alte packages-FK package_id).
 *
 * Migrations-Falle (CLAUDE.md): additiv, nullable+index, hasColumn-Guards = idempotent,
 * kein ->after(), keine ALTER-add-FK (SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_concepts', 'kind')) {
                $table->string('kind', 16)->default('concept')->index();
            }
        });
        Schema::table('foodalchemist_concept_slots', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_concept_slots', 'embedded_concept_id')) {
                $table->unsignedBigInteger('embedded_concept_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_concepts', 'kind')) {
            Schema::table('foodalchemist_concepts', fn (Blueprint $t) => $t->dropColumn('kind'));
        }
        if (Schema::hasColumn('foodalchemist_concept_slots', 'embedded_concept_id')) {
            Schema::table('foodalchemist_concept_slots', fn (Blueprint $t) => $t->dropColumn('embedded_concept_id'));
        }
    }
};
