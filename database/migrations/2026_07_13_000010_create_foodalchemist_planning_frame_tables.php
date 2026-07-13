<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R4.1 Planungs-Gerüst (Canvas-Ausbau, ROADMAP R4.1): das Soll-Gerüst eines Foodbooks
 * oder Konzepts als STRUKTURIERTE Daten — bewusst NICHT im Freitext-Canvas, sonst kann
 * R4.2 (Soll/Ist-Coverage) nichts messen und R6.1 (Brief→Konzept) nichts prompten.
 *
 * Drei Tabellen:
 *  - planning_frames       Kopf, owner polymorph (foodbook|concept, unique je Owner) +
 *                          Preisarchitektur p. P. (Zielpreis, Spanne). „Ein Gerüst, zwei
 *                          Konsumenten" — dieselbe Mechanik für beide Entitäten.
 *  - planning_frame_slots  Dramaturgie + Mengengerüst: Gang-/Stations-/Kapitel-Slots in
 *                          Reihenfolge, Soll-Gerichtszahl, Preis-Anker/Spanne je Slot.
 *  - planning_frame_rules  Quoten + Kunden-Politik: diet_quota (diet_form-Vokabular),
 *                          season_coverage, nogo_ingredient, nogo_allergen, allergen_line —
 *                          je Frame (slot_id NULL) oder je Slot.
 *
 * Kollisionsfreiheit food_dna-Canvas (DoD): die Canvas-Tabellen/-Templates bleiben
 * unangetastet — Prosa (no_gos, preis_positionierung) bleibt Kontext-Ebene, das Gerüst
 * ist die messbare Ebene daneben. Jedes Feld nullable — das Gerüst wächst, zwingt nicht.
 *
 * Konventionen 07 §7: keine CHECK-Constraints (rule_type/slot_type/operator als VARCHAR,
 * Enum im Model-Const), idempotent, chapter_id als nullable+index statt FK (Owner kann
 * Concept sein — dann gibt es kein Kapitel).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_planning_frames')) {
            Schema::create('foodalchemist_planning_frames', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->string('owner_type', 24);                     // foodbook | concept
                $table->unsignedBigInteger('owner_id');
                $table->string('status', 16)->default('draft');      // draft | aktiv
                $table->string('created_via', 24)->nullable();       // ui | mcp_tool | ai_gateway
                $table->decimal('target_price_pp', 10, 2)->nullable();  // Zielpreis pro Person (netto)
                $table->decimal('price_min_pp', 10, 2)->nullable();     // Preis-Spanne p. P. unten
                $table->decimal('price_max_pp', 10, 2)->nullable();     // Preis-Spanne p. P. oben
                $table->text('note')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['owner_type', 'owner_id'], 'fa_planning_frame_owner_unique');
            });
        }

        if (! Schema::hasTable('foodalchemist_planning_frame_slots')) {
            Schema::create('foodalchemist_planning_frame_slots', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('frame_id')->constrained('foodalchemist_planning_frames')->cascadeOnDelete();
                $table->integer('position')->default(0);
                $table->string('label');                              // „Vorspeisen", „Buffet-Station Süß", „Hauptgang"
                $table->string('slot_type', 16)->nullable();          // gang | station | kapitel
                $table->unsignedBigInteger('chapter_id')->nullable()->index(); // optionaler Ist-Bezug foodbook_chapters
                $table->integer('target_count')->nullable();          // Soll: n Gerichte in diesem Slot
                $table->decimal('price_anchor', 10, 2)->nullable();   // Preis-Anker je Gericht im Slot
                $table->decimal('price_min', 10, 2)->nullable();
                $table->decimal('price_max', 10, 2)->nullable();
                $table->boolean('is_pflicht')->default(false);        // Dramaturgie-Regel: Slot muss belegt sein
                $table->text('note')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['frame_id', 'position'], 'fa_planning_slot_frame_pos_idx');
            });
        }

        if (! Schema::hasTable('foodalchemist_planning_frame_rules')) {
            Schema::create('foodalchemist_planning_frame_rules', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('frame_id')->constrained('foodalchemist_planning_frames')->cascadeOnDelete();
                $table->foreignId('slot_id')->nullable()->constrained('foodalchemist_planning_frame_slots')->cascadeOnDelete();
                $table->string('rule_type', 24);                      // diet_quota | season_coverage | nogo_ingredient | nogo_allergen | allergen_line
                $table->string('ref_key', 48)->nullable();            // diet_form | Allergen-Key (EU-14) | Saison-Slug
                $table->unsignedBigInteger('ref_id')->nullable();     // z. B. season_id (team-pflegbares Vokabular)
                $table->string('operator', 8)->default('min');        // min | max | exact
                $table->decimal('value_num', 10, 2)->nullable();      // Quoten-Wert
                $table->string('unit', 8)->nullable();                // count | percent
                $table->text('value_text')->nullable();               // Freitext-Term (nogo_ingredient) / Linie-Beschreibung
                $table->string('severity', 8)->nullable();            // hart | weich (No-Gos)
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['frame_id', 'rule_type'], 'fa_planning_rule_frame_type_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_planning_frame_rules');
        Schema::dropIfExists('foodalchemist_planning_frame_slots');
        Schema::dropIfExists('foodalchemist_planning_frames');
    }
};
