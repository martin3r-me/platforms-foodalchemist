<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planung-Leitstelle · KI-Kopf persistent (#53).
 *
 * `foodalchemist_planning_sessions.plan_concept_id` (nullable): der vom „KI-Kopf" vorab
 * ausgearbeitete Concept-Plan-Entwurf ({@see ConceptGeneratorService::planAusBrief}) dieser
 * Session. Bisher war der Zeiger eine transiente Livewire-Prop und ging beim Reload verloren;
 * hier lebt er an der Session, sodass der geprüfte Plan einen Seiten-Neuladen übersteht und der
 * nächste Concept-Go ihn weiter als `existing_concept_id` referenziert.
 *
 * **Loser Zeiger, KEINE DB-FK** (bewusst kein `constrained()`): das Draft-Concept ist ein
 * eigenständiges Artefakt; die Beziehung + die Fail-soft-Prüfung (Concept weg/team-fremd → still
 * verwerfen) leben am Code. Reiner indexierter Fremdschlüssel (SQLite verträgt kein FK-`ALTER` —
 * Test-Harness-Kompatibilität, s. origin_dish_idea_id-Muster). Additiv/idempotent (hasColumn-Guard);
 * Bestands-Sessions bleiben `null`. Kein Deutsch im Schema (Regelwerk) — nur im comment.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_planning_sessions') && ! Schema::hasColumn('foodalchemist_planning_sessions', 'plan_concept_id')) {
            Schema::table('foodalchemist_planning_sessions', function (Blueprint $table) {
                $table->unsignedBigInteger('plan_concept_id')->nullable()->index()->after('generation_params')
                    ->comment('KI-Kopf: vorab ausgearbeiteter Concept-Plan-Entwurf dieser Session (planAusBrief) — der naechste Concept-Go referenziert ihn als existing_concept_id; persistent ueber Reload (#53)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('foodalchemist_planning_sessions') && Schema::hasColumn('foodalchemist_planning_sessions', 'plan_concept_id')) {
            Schema::table('foodalchemist_planning_sessions', function (Blueprint $table) {
                $table->dropColumn('plan_concept_id');
            });
        }
    }
};
