<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Format-Umbau F2e (Cutover-Cleanup): stilllegen der Alt-Editionen-Mechanik.
 * `concepts.format_id`/`format_position` waren der Ownership-FK, über den ein Concept
 * als Edition zu genau EINEM Format gehörte (Migration 2026_08_22_100002). Seit F2
 * ist die Zuordnung eine reine Referenz über `foodalchemist_format_slots` (type=concept,
 * concept_id) — ein Concept ist in mehreren Formaten nutzbar. `format-editions-to-slots`
 * hat den Bestand gespiegelt; die Spalten sind seitdem tot (nur noch Back-Compat-Fallbacks
 * lasen sie, die mit dieser Runde entfernt werden).
 *
 * SICHERHEIT: Der Drop bricht ab, falls noch eine `format_id`-Zuordnung existiert, die
 * NICHT als format_slot gespiegelt ist — dann erst `foodalchemist:format-editions-to-slots
 * --apply` laufen lassen. So geht keine Edition verloren.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_concepts')) {
            return;
        }

        if (Schema::hasColumn('foodalchemist_concepts', 'format_id')) {
            $orphans = (int) DB::table('foodalchemist_concepts as c')
                ->whereNotNull('c.format_id')
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('foodalchemist_format_slots as s')
                        ->whereColumn('s.format_id', 'c.format_id')
                        ->whereColumn('s.concept_id', 'c.id');
                })
                ->count();
            if ($orphans > 0) {
                throw new \RuntimeException(
                    "F2e-Drop abgebrochen: {$orphans} Editionen mit format_id ohne gespiegelten format_slot. "
                    . "Erst 'php artisan foodalchemist:format-editions-to-slots --apply' laufen lassen, dann erneut migrieren."
                );
            }

            Schema::table('foodalchemist_concepts', function (Blueprint $table) {
                // SQLite (Tests) unterstützt kein named-FK-Drop → dropConstrainedForeignId
                // baut die Tabelle um; auf MySQL wird der FK sauber gelöst.
                $table->dropConstrainedForeignId('format_id');
            });
        }

        if (Schema::hasColumn('foodalchemist_concepts', 'format_position')) {
            Schema::table('foodalchemist_concepts', function (Blueprint $table) {
                $table->dropColumn('format_position');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_concepts')) {
            return;
        }

        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_concepts', 'format_id')) {
                $table->foreignId('format_id')->nullable()->after('template_source_id')
                    ->constrained('foodalchemist_formats')->nullOnDelete();
            }
            if (! Schema::hasColumn('foodalchemist_concepts', 'format_position')) {
                $table->integer('format_position')->default(0)->after('format_id');
            }
        });
    }
};
