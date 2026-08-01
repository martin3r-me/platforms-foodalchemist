<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 30 E1 — Zeilen-Eingriff: der Produktionsauftrag wird vom reinen Rechenergebnis
 * zum Arbeitsdokument.
 *
 * Bisher galt Spec-18-Entscheid P2 kompromisslos: „Volle Neu-Explosion bei jeder Änderung —
 * Zeilen komplett löschen+neu anlegen". Das ist kein Schönheitsentscheid, sondern Mathematik:
 * die Ansatz-Rundung ist nicht additiv (`ceil(a)+ceil(b) ≠ ceil(a+b)`), also MUSS bei jeder
 * Ziel-Änderung alles neu gerechnet werden. Manuelle Eingriffe scheinen dem zu widersprechen.
 *
 * Die Auflösung weicht P2 NICHT auf, sie begrenzt seinen Zuständigkeitsbereich:
 *
 *  - `origin='computed'` — gehört der Explosion, wird bei jedem Recompute gelöscht und neu erzeugt.
 *  - `origin='manual'`   — freie Position („Brot beim Bäcker abholen"), liegt AUSSERHALB des
 *                          Recomputes und wird von ihm nie angefasst.
 *
 * Dazu ein Overlay an `computed`-Zeilen (Override, Streichung, Notiz, später Zuteilung und
 * Ausführung), das vor dem Löschen gerettet und per `recipe_id` wieder aufgesetzt wird — die
 * Verallgemeinerung des schon vorhandenen `pluck('note','recipe_id')`-Tricks.
 *
 * WARUM `recipe_id` als Schlüssel trägt: `PlanungsblattService::explodiere()` aggregiert
 * `$needBatches`/`$tiefe` keyed by recipe_id und emittiert GENAU EINE Zeile je Rezept (Top- und
 * Sub-Beitrag summiert, `tiefe = max`). Genau darauf beruht auch der Flaggschiff-Test
 * „EIN gemeinsamer Sauce-Bedarf, nicht zwei Zeilen". Der Unique-Index nagelt die Invariante fest.
 *
 * WARUM der Override in einer EIGENEN Spalte liegt und `ansaetze` nicht überschreibt: nur so
 * bleibt „manuell 2 — berechnet wären 3 · zurücksetzen" darstellbar. Dasselbe Muster fährt
 * `OrderService` mit `is_manual_qty`/`reset_qty`.
 *
 * WARUM Streichen kein Löschen ist: eine gelöschte Zeile käme beim nächsten Recompute sofort
 * zurück. `is_struck` klebt dagegen als Overlay am Rezept.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_production_order_lines')) {
            return;
        }

        if (! Schema::hasColumn('foodalchemist_production_order_lines', 'origin')) {
            Schema::table('foodalchemist_production_order_lines', function (Blueprint $table) {
                $table->string('origin', 8)->default('computed')
                    ->comment('computed = von der Explosion erzeugt (wird bei jedem Recompute ersetzt) | manual = freie Position');
                $table->string('titel')->nullable()
                    ->comment('Anzeigename einer freien Position (origin=manual, recipe_id IS NULL)');
                $table->decimal('manual_ansaetze', 10, 3)->nullable()
                    ->comment('Küchen-Override der Ansätze; `ansaetze` behält den berechneten Wert');
                $table->boolean('is_manual_ansaetze')->default(false);
                $table->boolean('is_struck')->default(false)
                    ->comment('gestrichen: bleibt sichtbar, fällt aus allen Summen und aus dem Druck');
                $table->string('struck_reason')->nullable();

                $table->index(['production_order_id', 'origin'], 'fa_prod_lines_order_origin_idx');
            });

            // ⚠️ Gürtel UND Hosenträger: bliebe `origin` irgendwo NULL, würde der neue Recompute
            // (`where('origin','computed')->forceDelete()`) diese Zeilen nicht mehr löschen und
            // bei JEDEM Lauf den Auftrag duplizieren. Der Default deckt Neuanlagen, dieses
            // Update den Bestand.
            DB::table('foodalchemist_production_order_lines')
                ->whereNull('origin')->update(['origin' => 'computed']);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_production_order_lines', 'origin')) {
            Schema::table('foodalchemist_production_order_lines', function (Blueprint $table) {
                $table->dropIndex('fa_prod_lines_order_origin_idx');
                $table->dropColumn(['origin', 'titel', 'manual_ansaetze', 'is_manual_ansaetze', 'is_struck', 'struck_reason']);
            });
        }
    }
};
