<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stufe 3 P3.1/P3.2 — Rezept-Attribute für den Auto-Produktionsplaner.
 *
 *  - default_station_id : Default-Posten des Rezepts (Routing des Verteilers). Ohne ihn
 *                         landet die Zeile „nicht zugeteilt" (Review), nie geraten.
 *  - max_vorlauf_tage   : Vorproduzierbarkeit/Haltbarkeit — wie viele Tage darf die Position
 *                         vorgezogen werden (Fond = lang, Salat = 0). Grenze fürs Vorziehen.
 *  - setup_time_min     : Rüstzeit, EINMAL je Produktionslauf (nicht je Batch) → nicht-lineare Zeit.
 *  - batch_max_kg/_pieces: Topf-Deckel des Rezepts; beim Rechnen gilt min(Rezept, Posten).
 *
 * Defaults reproduzieren das heutige Verhalten exakt: setup=0, kein Deckel ⇒ Koch-Batches =
 * heutige Ansätze ⇒ identische Arbeitszeit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_recipes', 'default_station_id')) {
                $table->unsignedBigInteger('default_station_id')->nullable()
                    ->comment('Default-Posten (Routing des Auto-Planers). Kein FK-Constraint: team-scoped, weiche Referenz.');
            }
            if (! Schema::hasColumn('foodalchemist_recipes', 'max_vorlauf_tage')) {
                $table->unsignedTinyInteger('max_vorlauf_tage')->nullable()
                    ->comment('Vorproduzierbarkeit in Tagen. NULL = unbekannt (Planer zieht nicht vor), 0 = nur am Tag.');
            }
            if (! Schema::hasColumn('foodalchemist_recipes', 'setup_time_min')) {
                $table->unsignedInteger('setup_time_min')->nullable()
                    ->comment('Rüstzeit einmal je Produktionslauf (nicht je Batch). NULL/0 = keine.');
            }
            if (! Schema::hasColumn('foodalchemist_recipes', 'batch_max_kg')) {
                $table->decimal('batch_max_kg', 10, 3)->nullable()
                    ->comment('Topf-Deckel kg je Koch-Vorgang. NULL = yield_kg (heutiges Verhalten).');
            }
            if (! Schema::hasColumn('foodalchemist_recipes', 'batch_max_pieces')) {
                $table->decimal('batch_max_pieces', 10, 2)->nullable()
                    ->comment('Topf-Deckel Stück je Koch-Vorgang. NULL = yield_pieces.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_recipes', function (Blueprint $table) {
            $table->dropColumn(['default_station_id', 'max_vorlauf_tage', 'setup_time_min', 'batch_max_kg', 'batch_max_pieces']);
        });
    }
};
