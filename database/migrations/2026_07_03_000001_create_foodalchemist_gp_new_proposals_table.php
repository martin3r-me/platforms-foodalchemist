<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 (MCP-Schreibkaskade): Staging-Queue für NEW-GP-Vorschläge aus dem
 * LLM-Pfad. GPs entstehen NIE hier (LA-First-Kuration bleibt WaWi, Einbahn-Sync
 * WaWi→FA) — die Queue sammelt nur, was beim Zutat-Matching keinen Treffer fand,
 * inkl. Kandidaten-Snapshot für die Review. Abgrenzung: `bulk_gp_proposals`
 * = Anreicherung BESTEHENDER GPs (gp_id-FK), hier = es gibt noch keinen GP.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_gp_new_proposals')) {
            return;
        }

        Schema::create('foodalchemist_gp_new_proposals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('name');                                     // vorgeschlagener GP-Name (Regelwerk §6 best effort)
            $table->string('name_normalized')->index();                 // Dedup-Schlüssel (lowercase, getrimmt)
            $table->string('hauptzutat_slug', 64)->nullable();
            $table->string('warengruppe', 64)->nullable();              // Vermutung, WaWi-Kuration entscheidet
            $table->string('zustand', 24)->nullable();                  // frisch | tk | trocken | konserviert (Vermutung)
            $table->text('kontext')->nullable();                        // wofür gebraucht (Rezept-/Foodbook-Bezug, Freitext)
            $table->string('quelle_kind', 24)->nullable();              // kind-Diskriminator (recipe | foodbook | …), kein FK
            $table->unsignedBigInteger('quelle_id')->nullable();
            $table->text('begruendung')->nullable();                    // warum kein Match reichte
            $table->json('match_snapshot')->nullable();                 // beste Kandidaten + Scores zum Proposal-Zeitpunkt
            $table->string('status', 16)->default('offen')->index();    // offen | uebernommen | verworfen
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'status', 'name_normalized'], 'fa_gp_new_prop_team_status_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_gp_new_proposals');
    }
};
