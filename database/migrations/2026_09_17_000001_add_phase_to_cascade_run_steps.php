<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 53 / Paket C: persistente Zwischen-Phase eines laufenden Kaskaden-Steps — bisher lebten die
 * Fortschritts-Texte NUR im Cache (`fa:recipe-gen:{runId}`, gelesen vom Rezept-Modal). Das Planungs-
 * Cockpit sah nur „läuft". `phase` trägt denselben Text als DB-Wahrheit (Cockpit + MCP), `phase_at`
 * den Zeitstempel des letzten Phasenwechsels (fürs Worker-Header „aktuelle Phase des jüngsten Steps").
 *
 * Additiv/idempotent (hasColumn-Guard, Muster 2026_08_14_000001).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_cascade_run_steps') && ! Schema::hasColumn('foodalchemist_cascade_run_steps', 'phase')) {
            Schema::table('foodalchemist_cascade_run_steps', function (Blueprint $table) {
                $table->string('phase', 120)->nullable()
                    ->comment('Persistente Zwischen-Phase (z. B. "KI schreibt das Rezept …"); null = keine aktive Phase');
                $table->timestamp('phase_at')->nullable()
                    ->comment('Zeitstempel des letzten Phasenwechsels');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('foodalchemist_cascade_run_steps') && Schema::hasColumn('foodalchemist_cascade_run_steps', 'phase')) {
            Schema::table('foodalchemist_cascade_run_steps', function (Blueprint $table) {
                $table->dropColumn(['phase', 'phase_at']);
            });
        }
    }
};
