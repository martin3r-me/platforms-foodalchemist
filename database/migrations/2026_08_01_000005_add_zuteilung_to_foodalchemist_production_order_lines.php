<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 30 E3 — Zuteilung an der Auftragszeile: Posten · Verantwortlicher · Vorproduktion.
 *
 * ⚠️ `assignee` ist ein STRING und bleibt es. Nie FK, nie `user_id`, und es gibt bewusst
 * KEINEN Index und KEINE Aggregation darüber. Auslastung wird ausschließlich je POSTEN
 * gerechnet. Das ist die Wand gegen den Weg Autocomplete → user_id → Verfügbarkeiten →
 * Schichten → Stundenkonten, also genau die Personalplanung, die Nicht-Ziel bleibt.
 * Sobald jemand „Auslastung je Person" baut, ist die Grenze gefallen — und man sieht es
 * sofort im Diff. Wording im UI konsequent „Verantwortlich", nie „zugewiesen an".
 *
 * VORPRODUKTION ALS OFFSET, NICHT ALS DATUM (`vorlauf_tage`, nicht `prep_date`):
 * Verschiebt sich das Event von Freitag auf Samstag, wandert der ganze Plan automatisch mit.
 * Absolute Daten würden still falsch — im schlimmsten Fall läge die Vorproduktion NACH dem
 * Liefertag. Mit dem Offset ist „Vorproduktion ≤ Liefertag" strukturell unverletzbar statt
 * geprüft. Damit bleibt auch Spec-18-Nicht-Ziel „Mehrtages-Produktionszeiträume" gewahrt:
 * der Auftrag hat weiter GENAU EIN Datum, nur die Zeilen haben einen Rückwärts-Schwanz.
 *
 * `production_date` behält seinen Namen (ein Rename kostet MCP-Tools, Blades, Tests und Docs
 * und bringt null Funktion), bekommt aber die Semantik LIEFER-/EINSATZTAG.
 *
 * `plan_date` ist eine ABGELEITETE Spalte, nur für `WHERE … BETWEEN` in der tages-
 * übergreifenden Sicht. Datumsarithmetik divergiert zwischen SQLite (Testsuite) und MySQL;
 * das Modul hat diese Abwägung schon einmal zugunsten der Portabilität entschieden.
 * GENAU EIN SCHREIBER: `ProductionOrderService::syncPlanDates()`. Kein anderer Code setzt
 * die Spalte — sonst driftet sie still gegen `vorlauf_tage`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_production_order_lines')
            || Schema::hasColumn('foodalchemist_production_order_lines', 'station_id')) {
            return;
        }

        Schema::table('foodalchemist_production_order_lines', function (Blueprint $table) {
            $table->foreignId('station_id')->nullable()
                ->constrained('foodalchemist_production_stations')->nullOnDelete()
                ->comment('Posten; NULL = unverplant (zählt gegen keine Kapazität, wird aber ausgewiesen)');
            $table->string('assignee', 120)->nullable()
                ->comment('Freier Name. KEIN FK, KEIN Index, KEINE Aggregation — siehe Migrations-Docblock.');
            $table->unsignedTinyInteger('vorlauf_tage')->default(0)
                ->comment('Rückwärts-Offset auf den Liefertag: 0 = am Tag selbst, 1 = Vortag …');
            $table->date('plan_date')->nullable()
                ->comment('ABGELEITET = production_date − vorlauf_tage. Einziger Schreiber: syncPlanDates().');

            $table->index(['production_order_id', 'station_id'], 'fa_prod_lines_order_station_idx');
            $table->index(['team_id', 'plan_date'], 'fa_prod_lines_team_plan_idx');
        });

        // Backfill: Bestand ist per Definition konsistent (kein Vorlauf, Plan = Liefertag).
        // BEWUSST als korrelierte Subquery, nicht als `UPDATE … JOIN`: letzteres ist
        // MySQL-only und würde die SQLite-Testsuite brechen (dokumentierte Modul-Falle).
        DB::statement('
            UPDATE foodalchemist_production_order_lines
            SET plan_date = (
                SELECT production_date FROM foodalchemist_production_orders o
                WHERE o.id = foodalchemist_production_order_lines.production_order_id
            )
            WHERE plan_date IS NULL
        ');
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_production_order_lines', 'station_id')) {
            Schema::table('foodalchemist_production_order_lines', function (Blueprint $table) {
                $table->dropForeign(['station_id']);
                $table->dropIndex('fa_prod_lines_order_station_idx');
                $table->dropIndex('fa_prod_lines_team_plan_idx');
                $table->dropColumn(['station_id', 'assignee', 'vorlauf_tage', 'plan_date']);
            });
        }
    }
};
