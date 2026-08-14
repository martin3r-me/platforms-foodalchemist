<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gestufte Kaskade (Gate pro Ebene): der Cockpit-Go läuft nicht mehr eager durch, sondern hält je Ebene
 * an (Concept → [Freigabe] → Gerichte → [Freigabe] → Basisrezepte).
 *
 * - `foodalchemist_cascade_runs.staged` (bool, default false): markiert einen gestuften Lauf. Nur die
 *   Cockpit-Scopes (rezept|gericht|concept) sind staged; die Output-Voll-Kaskaden (foodbook/speisekarte/
 *   speiseplan) bleiben false = unverändert eager (Sammel-Review).
 * - `foodalchemist_cascade_run_steps.deferred` (json, nullable): trägt die aufgeschobene Fortsetzung eines
 *   Steps bis zu seiner Freigabe — Concept: {fanout:{mode,trend_doc_id,planning_session_id}}; Gericht/Rezept:
 *   {children:{offene,params,user_id}}. Wird bei der Freigabe abgearbeitet und wieder genullt.
 *
 * Additiv/idempotent (hasColumn-Guards). Bestandsläufe bleiben staged=false → unverändertes Verhalten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_cascade_runs') && ! Schema::hasColumn('foodalchemist_cascade_runs', 'staged')) {
            Schema::table('foodalchemist_cascade_runs', function (Blueprint $table) {
                $table->boolean('staged')->default(false)
                    ->comment('Gate pro Ebene (Cockpit-Läufe); vollkaskade bleibt false = eager');
            });
        }

        if (Schema::hasTable('foodalchemist_cascade_run_steps') && ! Schema::hasColumn('foodalchemist_cascade_run_steps', 'deferred')) {
            Schema::table('foodalchemist_cascade_run_steps', function (Blueprint $table) {
                $table->json('deferred')->nullable()
                    ->comment('Aufgeschobene Fortsetzung bis Freigabe (concept:fanout / gericht|rezept:children)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('foodalchemist_cascade_runs') && Schema::hasColumn('foodalchemist_cascade_runs', 'staged')) {
            Schema::table('foodalchemist_cascade_runs', function (Blueprint $table) {
                $table->dropColumn('staged');
            });
        }
        if (Schema::hasTable('foodalchemist_cascade_run_steps') && Schema::hasColumn('foodalchemist_cascade_run_steps', 'deferred')) {
            Schema::table('foodalchemist_cascade_run_steps', function (Blueprint $table) {
                $table->dropColumn('deferred');
            });
        }
    }
};
