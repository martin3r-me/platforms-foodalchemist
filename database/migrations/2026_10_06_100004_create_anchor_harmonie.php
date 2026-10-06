<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 60 · P2: Harmonie als schlanke, eindeutige Tabelle.
 *
 * `pairing_anchor_edges` trug Spalten aus drei Pairing-Generationen (type, weight, axis, source,
 * source_slug, evidence, uuid, team_id, legacy_id). Auf demo stehen dort nur noch Inspire-Kanten
 * (type=aroma, axis=harmony, level 2|3) — alle anderen Spalten sind konstant oder leer.
 *
 * Neu: `anchor_harmonie` (anchor_a_id, anchor_b_id, stufe 2|3), jedes Paar in beiden Richtungen.
 * Stufe 1 wird nicht gespeichert (Paar ohne Zeile = Stufe 1, gemessen). Einzige Lesestelle:
 * {@see \Platform\FoodAlchemist\Services\Pairing\AnkerGraph}.
 *
 * Übernahme: höchste Stufe je gerichtetem Paar aus `pairing_anchor_edges` (nur level 2/3), dann
 * fehlende Gegenrichtungen ergänzen, dann `pairing_anchor_edges` droppen. Sicherung der Kanten
 * liegt nicht vor, weil sie aus foodpairing_kompakt.db jederzeit neu entstehen (inspire-import).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_anchor_harmonie', function (Blueprint $table) {
            $table->foreignId('anchor_a_id')->constrained('foodalchemist_vocab_pairing_anchors', 'id', 'fa_harm_a_fk')->cascadeOnDelete();
            $table->foreignId('anchor_b_id')->constrained('foodalchemist_vocab_pairing_anchors', 'id', 'fa_harm_b_fk')->cascadeOnDelete();
            $table->unsignedTinyInteger('stufe')->comment('3 = best match, 2 = good match; Stufe 1 wird nicht gespeichert');
            $table->primary(['anchor_a_id', 'anchor_b_id']);
            $table->index(['anchor_b_id', 'stufe'], 'fa_harm_b_stufe_idx');
        });

        if (Schema::hasTable('foodalchemist_pairing_anchor_edges')) {
            DB::statement('INSERT INTO foodalchemist_anchor_harmonie (anchor_a_id, anchor_b_id, stufe)
                SELECT anchor_a_id, anchor_b_id, MAX(level) FROM foodalchemist_pairing_anchor_edges
                WHERE level IN (2, 3) AND anchor_a_id <> anchor_b_id
                GROUP BY anchor_a_id, anchor_b_id');
            // Gegenrichtung ergänzen, falls eine Kante nur einseitig vorlag.
            DB::statement('INSERT INTO foodalchemist_anchor_harmonie (anchor_a_id, anchor_b_id, stufe)
                SELECT h.anchor_b_id, h.anchor_a_id, h.stufe FROM foodalchemist_anchor_harmonie h
                WHERE NOT EXISTS (SELECT 1 FROM foodalchemist_anchor_harmonie r
                    WHERE r.anchor_a_id = h.anchor_b_id AND r.anchor_b_id = h.anchor_a_id)');
            Schema::drop('foodalchemist_pairing_anchor_edges');
        }
    }

    public function down(): void
    {
        // Bewusst keine Rückrichtung: die Kanten entstehen aus foodpairing_kompakt.db neu
        // (foodalchemist:inspire-import).
        Schema::dropIfExists('foodalchemist_anchor_harmonie');
    }
};
