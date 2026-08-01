<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 30 E6 — Küchen-Ausführung: Zeilen abhaken.
 *
 * Bewusst NUR abhaken (Dominique, 2026-08-01): Zeilen-Status plus Zeitstempel, KEINE
 * Ist-Mengen. Damit bleibt „Ist-vs-Plan-Ausbeute-Tracking" das Nicht-Ziel, das Spec 18
 * definiert hat, und es entsteht kein halb gepflegtes Zahlenfeld, dem später niemand traut.
 *
 * `done_by` trägt bewusst KEINEN Fremdschlüssel — dieselbe Konvention wie `created_by` am
 * Auftrag. Es ist eine Protokoll-Notiz, kein Beziehungsgeflecht.
 *
 * ⚠️ Diese drei Spalten stehen NICHT in `ProductionOrderService::OVERLAY_FELDER`, und das ist
 * Absicht: `recomputeOrder()` bricht bei allem außer `planned` sofort ab, abgehakt wird aber
 * erst ab `in_progress`. Ein Recompute kann eine abgehakte Zeile also nie erleben. Die
 * Invariante ist testgesichert — bricht sie irgendwann, hätte man sonst stumm eingefrorene
 * Häkchen an inzwischen geänderten Mengen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_production_order_lines')
            || Schema::hasColumn('foodalchemist_production_order_lines', 'line_status')) {
            return;
        }

        Schema::table('foodalchemist_production_order_lines', function (Blueprint $table) {
            $table->string('line_status', 12)->default('open')
                ->comment('open|in_progress|done|skipped — Checkliste der Küche, NICHT der Beleg-Status des Auftrags');
            $table->dateTime('done_at')->nullable();
            $table->unsignedBigInteger('done_by')->nullable()->comment('kein FK — Protokoll-Notiz, Konvention wie created_by');

            $table->index(['production_order_id', 'line_status'], 'fa_prod_lines_order_lstatus_idx');
        });

        // Gürtel und Hosenträger wie bei `origin`: bliebe der Status irgendwo NULL, fiele die
        // Zeile aus jeder Fortschritts-Zählung heraus, ohne dass es auffällt.
        DB::table('foodalchemist_production_order_lines')
            ->whereNull('line_status')->update(['line_status' => 'open']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_production_order_lines', 'line_status')) {
            Schema::table('foodalchemist_production_order_lines', function (Blueprint $table) {
                $table->dropIndex('fa_prod_lines_order_lstatus_idx');
                $table->dropColumn(['line_status', 'done_at', 'done_by']);
            });
        }
    }
};
