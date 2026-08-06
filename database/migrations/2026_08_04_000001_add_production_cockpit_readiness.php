<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 35 K4 — Cockpit-Readiness: Zeilen-Blocker/Skip/Start + append-only Ereignisprotokoll.
 *
 * IDEMPOTENT (hasColumn/hasTable-Guards): Diese Migration wurde einmal ausgerollt, dann durch
 * einen Fehl-Revert (Commit 15168aa) aus dem Baum gelöscht und hier wiederhergestellt. Auf einer
 * DB, die sie bereits gefahren hat, existieren Spalten/Tabelle schon — die Guards verhindern
 * „column/table already exists" beim erneuten migrate; auf einer frischen DB legt sie alles an.
 */
return new class extends Migration
{
    private const LINES = 'foodalchemist_production_order_lines';

    public function up(): void
    {
        Schema::table(self::LINES, function (Blueprint $table) {
            if (! Schema::hasColumn(self::LINES, 'blocked_reason')) {
                $table->string('blocked_reason', 80)->nullable();
            }
            if (! Schema::hasColumn(self::LINES, 'blocked_note')) {
                $table->text('blocked_note')->nullable();
            }
            if (! Schema::hasColumn(self::LINES, 'skipped_reason')) {
                $table->string('skipped_reason', 80)->nullable();
            }
            if (! Schema::hasColumn(self::LINES, 'started_at')) {
                $table->dateTime('started_at')->nullable();
            }
            if (! Schema::hasColumn(self::LINES, 'started_by')) {
                $table->unsignedBigInteger('started_by')->nullable();
            }
        });

        if (! Schema::hasTable('foodalchemist_production_events')) {
            Schema::create('foodalchemist_production_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('team_id');
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('line_id')->nullable();
                $table->string('event_type', 60);
                $table->string('from_state', 40)->nullable();
                $table->string('to_state', 40)->nullable();
                $table->string('reason_code', 80)->nullable();
                $table->text('note')->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['team_id', 'created_at'], 'fa_prod_events_team_created_idx');
                $table->index(['order_id', 'created_at'], 'fa_prod_events_order_created_idx');
                $table->index(['line_id', 'created_at'], 'fa_prod_events_line_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_production_events');
        Schema::table(self::LINES, function (Blueprint $table) {
            foreach (['blocked_reason', 'blocked_note', 'skipped_reason', 'started_at', 'started_by'] as $col) {
                if (Schema::hasColumn(self::LINES, $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
