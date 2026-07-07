<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GP-Bulk-Autopilot: Vorschlags-Speicher analog `foodalchemist_bulk_proposals` (Rezept),
 * aber an GPs gebunden. Eigene Tabelle statt recipe_id nullable → additiv, kein change()
 * (SQLite/MySQL-sicher), kein Risiko für den Rezept-Pfad. Läufe teilen sich `bulk_runs`
 * (target-agnostisch). GL-07: Job schreibt nur Vorschläge, Übernahme bleibt interaktiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_bulk_gp_proposals')) {
            return;
        }

        Schema::create('foodalchemist_bulk_gp_proposals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('run_id')->constrained('foodalchemist_bulk_runs')->cascadeOnDelete();
            $table->foreignId('gp_id')->constrained('foodalchemist_gps')->cascadeOnDelete();
            $table->string('field', 32);                                // condition | tags | allergene | naehrwerte
            $table->json('value')->nullable();                          // Vorschlagswert (feld-spezifisch)
            $table->decimal('confidence', 4, 3)->nullable();
            $table->text('reasoning')->nullable();
            $table->unsignedBigInteger('call_log_id')->nullable();
            $table->string('status', 16)->default('offen');            // offen | uebernommen | verworfen | leer
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'status'], 'fa_bulk_gp_prop_run_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_bulk_gp_proposals');
    }
};
