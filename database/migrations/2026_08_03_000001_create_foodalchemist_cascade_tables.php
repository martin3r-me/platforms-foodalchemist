<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kaskaden-Motor (Planungs-Kaskade, P0): der geteilte Spine hinter dem „Go".
 *
 * Ein `cascade_run` = EIN Go auf einer Planung (oder später einem Ausgabe-Frame). Er fächert in
 * `cascade_run_steps` auf — ein Baum `concept → gericht → rezept/gp` (parent_step_id). Für P0
 * (scope rezept|gericht) hat ein Run genau EINEN Step, der einen `GenerateRecipeJob` umhüllt
 * (`generator_run_id` = dessen Cache-Run-ID zum Pollen). In P1+ wächst der Baum zur vollen
 * Kaskade; das Schema trägt beides ohne Umbau.
 *
 * **Invariante:** Der Motor erzeugt NUR Drafts (Rezept `status=draft`, GP `tentative`) — die
 * Freigabe an eine Live-Ausgabe ist das zweite Gate (Sammel-Review, P2). Der Run trägt kein
 * eigenes Grounding; er orchestriert die bestehenden Erzeugungs-Services.
 *
 * Alle Zeiger sind lose (unsignedBigInteger+index, KEINE ALTER-FK) — cross-DB-sicher. Index-Namen
 * EXPLIZIT + kurz (MySQL 64-Zeichen-Limit). Schema englisch. Additiv/idempotent (hasTable-Guards).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_cascade_runs')) {
            Schema::create('foodalchemist_cascade_runs', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->index('fa_casc_run_team_ix');
                $table->unsignedBigInteger('planning_session_id')->nullable()
                    ->index('fa_casc_run_sess_ix')
                    ->comment('Auslösende Planung (foodalchemist_planning_sessions.id) — loser Zeiger');
                $table->string('scope', 20)->comment('rezept|gericht|concept|vollkaskade');
                $table->string('creative_mode', 20)->default('voll_kreativ')->comment('voll_kreativ|hybrid|datenbank');
                $table->text('brief')->nullable();
                $table->json('params')->nullable()->comment('Generierungs-Parameter (niveau, ziel_vk_eur, …)');
                $table->string('status', 20)->default('running')->comment('running|review|done|failed');
                // Ausgabe-Frame-Herkunft (P3+: foodbook|speisekarte|speiseplan|concept) — loser Zeiger.
                $table->string('source_owner_type', 20)->nullable();
                $table->unsignedBigInteger('source_owner_id')->nullable();
                $table->string('created_via')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('foodalchemist_cascade_run_steps')) {
            Schema::create('foodalchemist_cascade_run_steps', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->index('fa_casc_step_team_ix');
                $table->unsignedBigInteger('cascade_run_id')->index('fa_casc_step_run_ix');
                $table->unsignedBigInteger('parent_step_id')->nullable()
                    ->index('fa_casc_step_parent_ix')
                    ->comment('Baum: concept → gericht → rezept/gp');
                $table->string('kind', 20)->comment('concept|gericht|rezept|gp');
                $table->string('label')->nullable();
                $table->string('status', 20)->default('queued')->comment('queued|running|done|failed|skipped');
                $table->string('ref_type', 20)->nullable()->comment('recipe|concept|gp — erzeugtes Artefakt');
                $table->unsignedBigInteger('ref_id')->nullable();
                $table->string('generator_run_id')->nullable()->comment('GenerateRecipeJob-Cache-Run-ID (Polling)');
                $table->text('error')->nullable();
                $table->integer('sort')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_cascade_run_steps');
        Schema::dropIfExists('foodalchemist_cascade_runs');
    }
};
