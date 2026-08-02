<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planungs-/Kreativ-Ebene (Doppel-Diamant, Spec 08): die owner-lose Planungs-Session.
 *
 * Die Session ist der Container VOR dem Grounding: Analyse + Skizzen (Divergenz) + Planung,
 * bevor ein Konzept/Rezept existiert. Sie besitzt KEINEN PlanningFrame (dessen OWNER_TYPES sind
 * dreifach hart auf foodbook|concept verdrahtet) — der Frame entsteht erst am Concept beim „Go".
 *
 * Zusätzlich: die bestehende Skizzen-Ebene (dish_ideas/-groups) bekommt einen dritten Owner
 * (`planning_session_id`, additiv zu chapter/concept — 3-Wege-XOR im IdeenService), und erzeugte
 * Artefakte (recipes/concepts) bekommen `source_knowledge_document_id` als first-class Trend-Herkunft.
 *
 * Alle Zusatz-Spalten sind lose Zeiger (unsignedBigInteger+index, KEINE ALTER-FK) — cross-DB-sicher.
 * Index-Namen EXPLIZIT + kurz (MySQL 64-Zeichen-Limit; die Auto-Namen sprengen es). Schema englisch.
 * Additiv/idempotent (hasTable/hasColumn-Guards).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_planning_sessions')) {
            Schema::create('foodalchemist_planning_sessions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->index('fa_plan_sess_team_ix');
                $table->unsignedBigInteger('source_knowledge_document_id')->nullable()
                    ->index('fa_plan_sess_src_ix')
                    ->comment('Quell-Trend (knowledge_documents.id) — loser Zeiger, kein Cascade');
                $table->string('title');
                $table->text('brief')->nullable();
                $table->text('analysis')->nullable();
                $table->string('creative_mode', 20)->default('voll_kreativ')->comment('voll_kreativ|hybrid|datenbank');
                $table->string('status', 20)->default('divergenz')->comment('divergenz|konvergenz|erledigt');
                $table->string('created_via')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // Dritter Owner an der bestehenden Skizzen-Ebene (3-Wege: chapter XOR concept XOR session).
        if (Schema::hasTable('foodalchemist_dish_ideas') && ! Schema::hasColumn('foodalchemist_dish_ideas', 'planning_session_id')) {
            Schema::table('foodalchemist_dish_ideas', function (Blueprint $table) {
                $table->unsignedBigInteger('planning_session_id')->nullable()->index('fa_dish_ideas_plan_sess_ix')->after('concept_id');
            });
        }
        if (Schema::hasTable('foodalchemist_dish_idea_groups') && ! Schema::hasColumn('foodalchemist_dish_idea_groups', 'planning_session_id')) {
            Schema::table('foodalchemist_dish_idea_groups', function (Blueprint $table) {
                $table->unsignedBigInteger('planning_session_id')->nullable()->index('fa_dish_grp_plan_sess_ix')->after('concept_id');
            });
        }

        // First-class Trend-Herkunft an erzeugten Artefakten (joinbar „alle Entwürfe aus Trend X").
        if (Schema::hasTable('foodalchemist_recipes') && ! Schema::hasColumn('foodalchemist_recipes', 'source_knowledge_document_id')) {
            Schema::table('foodalchemist_recipes', function (Blueprint $table) {
                $table->unsignedBigInteger('source_knowledge_document_id')->nullable()->index('fa_recipes_src_kdoc_ix');
            });
        }
        if (Schema::hasTable('foodalchemist_concepts') && ! Schema::hasColumn('foodalchemist_concepts', 'source_knowledge_document_id')) {
            Schema::table('foodalchemist_concepts', function (Blueprint $table) {
                $table->unsignedBigInteger('source_knowledge_document_id')->nullable()->index('fa_concepts_src_kdoc_ix');
            });
        }
    }

    public function down(): void
    {
        foreach (['foodalchemist_dish_ideas', 'foodalchemist_dish_idea_groups'] as $t) {
            if (Schema::hasColumn($t, 'planning_session_id')) {
                Schema::table($t, fn (Blueprint $table) => $table->dropColumn('planning_session_id'));
            }
        }
        foreach (['foodalchemist_recipes', 'foodalchemist_concepts'] as $t) {
            if (Schema::hasColumn($t, 'source_knowledge_document_id')) {
                Schema::table($t, fn (Blueprint $table) => $table->dropColumn('source_knowledge_document_id'));
            }
        }
        Schema::dropIfExists('foodalchemist_planning_sessions');
    }
};
