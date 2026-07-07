<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M7-06 / V-15: Bulk-Autopilot — Runs (Fortschritts-Polling) + Vorschläge
 * (Review-Liste; Accept bleibt interaktiv, GL-07 »nie Auto-Persistenz«).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_bulk_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 32)->default('enrich');             // enrich (Basis) | enrich_vk (D-6 §4.7, später)
            $table->string('status', 16)->default('running');         // running | done
            $table->unsignedInteger('total')->default(0);             // Rezepte
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('failed')->default(0);            // Items mit Fehler (Kill-Switch, Provider …)
            $table->timestamps();
        });

        Schema::create('foodalchemist_bulk_proposals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('run_id')->constrained('foodalchemist_bulk_runs')->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained('foodalchemist_recipes')->cascadeOnDelete();
            $table->string('field', 32);                                // description | kategorie | geschmack …
            $table->json('value')->nullable();                          // Vorschlagswert (feld-spezifisch)
            $table->decimal('confidence', 4, 3)->nullable();
            $table->text('reasoning')->nullable();
            $table->unsignedBigInteger('call_log_id')->nullable();     // Accept stempelt (06_KI §5 P3)
            $table->string('status', 16)->default('offen');            // offen | uebernommen | verworfen | leer
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_bulk_proposals');
        Schema::dropIfExists('foodalchemist_bulk_runs');
    }
};
