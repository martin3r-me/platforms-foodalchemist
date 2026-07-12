<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R2.6 — Feedback je Gericht/Basisrezept (menschliche Quelle: Küche · Kunde · Event).
 * Eine Tabelle deckt beide ab (Gericht = is_sales_recipe=1, Basisrezept = 0 —
 * dieselbe recipes-Tabelle). Küchen-Feedback trägt strukturierte Achsen
 * (Machbarkeit/Aufwand/Geschmack/Gäste-Reaktion) = Entwicklungs-Motor; Kunde/Event
 * i. d. R. nur Score + Kommentar. `spawned_recipe_id` = „Weiterentwickeln"-Lineage
 * (aus einem Feedback → Draft-Iteration). Aggregation (Ø/Count) wird on-read
 * gerechnet — keine Recompute-Spalten auf recipes.
 *
 * D1: Feedback ist immer team-eigen (team_id NOT NULL); Eltern-Team sieht die
 * Kind-Einträge aggregiert über visibleToTeam (Team-Ancestry).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_recipe_feedback')) {
            return;
        }

        Schema::create('foodalchemist_recipe_feedback', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();      // D1: immer team-eigen
            $table->foreignId('recipe_id')->constrained('foodalchemist_recipes')->cascadeOnDelete();

            $table->string('quelle', 16)->index();               // FeedbackQuelle: kueche | kunde | event
            $table->unsignedTinyInteger('score')->nullable();    // Gesamt-Score 1–5

            // strukturierte Küchen-Achsen (1–5), nur bei quelle=kueche gefüllt
            $table->unsignedTinyInteger('machbarkeit')->nullable();
            $table->unsignedTinyInteger('aufwand')->nullable();
            $table->unsignedTinyInteger('geschmack')->nullable();
            $table->unsignedTinyInteger('gaeste_reaktion')->nullable();

            $table->text('comment')->nullable();

            // optionaler Kontext (kein harter FK — kontext_id ist polymorph concept|event)
            $table->string('kontext_kind', 16)->nullable();      // concept | event | NULL
            $table->unsignedBigInteger('kontext_id')->nullable();
            $table->date('kontext_datum')->nullable();
            $table->string('kontext_label')->nullable();

            // „Weiterentwickeln"-Brücke: Feedback → Draft-Rezept-Iteration (Lineage)
            $table->foreignId('spawned_recipe_id')->nullable();
            $table->foreign('spawned_recipe_id', 'fa_recipe_feedback_spawned_fk')
                ->references('id')->on('foodalchemist_recipes')->nullOnDelete();

            $table->unsignedBigInteger('author_user_id')->nullable()->index(); // kein Cross-Modul-FK auf core users
            $table->string('created_via', 24)->nullable()->index();            // fa_ui | mcp | NULL

            $table->timestamps();
            $table->softDeletes();

            $table->index(['recipe_id', 'deleted_at'], 'fa_recipe_feedback_recipe_idx'); // Aggregation je Rezept
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_recipe_feedback');
    }
};
