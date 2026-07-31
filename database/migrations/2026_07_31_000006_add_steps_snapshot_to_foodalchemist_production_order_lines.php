<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 27 Phase 4 — Schritt-Snapshot am Produktionsauftrag.
 *
 * Eine Auftragszeile friert die Zubereitung ein, sobald der Auftrag läuft
 * (`zubereitung` = Text-Snapshot, Migration 2026_07_22_000001). Seit Spec 27 ist die
 * Anleitung aber eine Schrittfolge — ohne strukturierten Snapshot könnte ein
 * laufender Auftrag keine Schritt-Karten drucken, sondern nur den Fließtext.
 *
 * `steps_snapshot` friert deshalb `[{nr, phase, text, fotos:[{url, caption}]}]` mit ein.
 * BEWUSST ohne Fotodateien: eingefroren wird der Verweis, nicht das Bild — ein
 * gelöschtes Foto fehlt dann im Druck, statt Speicher zu duplizieren.
 * NULL = Alt-Auftrag → Lesepfade fallen auf den Text-Snapshot zurück.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_production_order_lines')
            || Schema::hasColumn('foodalchemist_production_order_lines', 'steps_snapshot')) {
            return;
        }

        Schema::table('foodalchemist_production_order_lines', function (Blueprint $table) {
            $table->json('steps_snapshot')->nullable()
                ->comment('Snapshot der recipe_steps — friert bei in_progress ein (Spec 27)');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_production_order_lines', 'steps_snapshot')) {
            Schema::table('foodalchemist_production_order_lines', function (Blueprint $table) {
                $table->dropColumn('steps_snapshot');
            });
        }
    }
};
