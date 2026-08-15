<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skizzen-Integration (Roadmap Planung-Leitstelle · Etappe 4 · Teil 2a — Lineage).
 *
 * `foodalchemist_cascade_runs.origin_dish_idea_id` (nullable): der Kaskaden-Lauf, der aus einer
 * Divergenz-Board-Skizze heraus gestartet wurde (Skizze → Gericht-Tab → „Go"), trägt hier die
 * Ursprungs-Skizze. Das ist die Voraussetzung für die Status-Rückkopplung (Teil 2b): die
 * Skizzen-Karte kann so den Stand des aus ihr entstandenen Laufs (läuft/prüfen/fertig) anzeigen.
 *
 * **Loser Zeiger, KEINE DB-FK** (bewusst kein `constrained()`): der Lauf ist ein eigenständiges
 * Artefakt und soll die Skizze überleben; die Beziehung lebt am Model ({@see FoodAlchemistCascadeRun}).
 * Reiner indexierter Fremdschlüssel (SQLite verträgt kein FK-`ALTER` — Test-Harness-Kompatibilität,
 * s. cohesion_warning-Muster). Additiv/idempotent (hasColumn-Guard); Bestandsläufe bleiben `null`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_cascade_runs') && ! Schema::hasColumn('foodalchemist_cascade_runs', 'origin_dish_idea_id')) {
            Schema::table('foodalchemist_cascade_runs', function (Blueprint $table) {
                $table->unsignedBigInteger('origin_dish_idea_id')->nullable()->index()
                    ->comment('Ursprungs-Skizze (Divergenz-Board), aus der dieser Lauf gestartet wurde — Lineage für die Status-Rückkopplung auf die Skizzen-Karte');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('foodalchemist_cascade_runs') && Schema::hasColumn('foodalchemist_cascade_runs', 'origin_dish_idea_id')) {
            Schema::table('foodalchemist_cascade_runs', function (Blueprint $table) {
                $table->dropColumn('origin_dish_idea_id');
            });
        }
    }
};
