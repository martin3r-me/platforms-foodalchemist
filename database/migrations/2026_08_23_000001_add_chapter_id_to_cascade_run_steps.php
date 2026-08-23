<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec-42-Vollzug Stufe 3a — Step↔Kapitel-Zuordnung.
 *
 * Damit ein per-Kapitel-Teil-Lauf ({@see \Platform\FoodAlchemist\Services\PlanningCascadeService::starteKapitelKaskade})
 * und die kapitelgruppierte Worker-Ansicht Step↔Kapitel zuordnen können, trägt der Step jetzt sein
 * Ziel-Kapitel (`chapter_id`) und optional den zugrundeliegenden Frame-Slot (`slot_id`). Beide werden im
 * gemeinsamen Dispatch-Helfer gesetzt (Voll- UND Kapitel-Kaskade), aber nur für owner_type='foodbook'.
 *
 * Additiv/idempotent (hasColumn-Guards). Nullable + loser Index, KEIN FK (cross-DB / SQLite-Harness).
 * Bestandssteps + alle Nicht-Foodbook-Steps bleiben NULL — kein Backfill nötig.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_cascade_run_steps')) {
            return;
        }
        Schema::table('foodalchemist_cascade_run_steps', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_cascade_run_steps', 'chapter_id')) {
                $table->unsignedBigInteger('chapter_id')->nullable()->index('fa_casc_step_chapter_ix')
                    ->comment('Ziel-Kapitel (nur owner_type=foodbook) — für per-Kapitel-Lauf + Worker-Gruppierung');
            }
            if (! Schema::hasColumn('foodalchemist_cascade_run_steps', 'slot_id')) {
                $table->unsignedBigInteger('slot_id')->nullable()
                    ->comment('Zugrundeliegender Frame-Slot (optional, für Rück-Referenz)');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_cascade_run_steps')) {
            return;
        }
        Schema::table('foodalchemist_cascade_run_steps', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_cascade_run_steps', 'chapter_id')) {
                $table->dropColumn('chapter_id');
            }
            if (Schema::hasColumn('foodalchemist_cascade_run_steps', 'slot_id')) {
                $table->dropColumn('slot_id');
            }
        });
    }
};
