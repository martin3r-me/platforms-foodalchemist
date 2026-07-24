<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 19 „Foodbook-Leitstelle A–Z" — M4: Kreativ-Skizzen-Ebene (dish_ideas + dish_idea_groups).
 *
 * Die Kreativ-Phase erlaubt freie Ideen ODER Bestand-Refs, Einzel ODER Paket-Gruppe — Status
 * `entwurf`, erdet NICHTS. **Invariante: Ideen erzeugen NIE Rezepte/GPs/Konzepte — erst das
 * Kapitel-Go (E7.3) materialisiert.** XOR-Zugehörigkeit chapter_id/concept_id wird auf
 * Service-Ebene (IdeenService, E6.2) erzwungen; DB hält beide FKs nullable + index.
 *
 * Namens-Hinweis (Spec-Shorthand → Schema, Schema-Sprache = Englisch, VERBINDLICH):
 * Die Spec-Prosa schreibt die Content-Felder deutsch (`titel`, `beschreibung`, `ziel_form`,
 * `quelle_meta`). Die LIVE-Chapters-Tabelle beweist die Konvention: Content-Felder sind
 * ENGLISCH (`title`, `consumer_title`, `description`). Daher hier:
 *   titel → title · beschreibung → description · ziel_form → target_form · quelle_meta → source_meta.
 * Die deutschen ENUM-WERTE bleiben (einzel|paket, entwurf|verworfen|freigegeben) — analog zu
 * `pricing_mode` (paket|einzel|gemischt, E4.1). Klassifikations-Strings sind per Model-Const
 * gedeckelt (Vokabular-Pflicht, Entscheidung 6). Downstream-MCP (E6.5) mappt Arg → Spalte.
 *
 * Additiv/idempotent (hasTable-Guards). FK cascadeOnDelete für Owner (chapter/concept),
 * group_id nullOnDelete (Spec explizit); Ergebnis-Zeiger (sales_recipe_id/generated_recipe_id/
 * materialized_concept_id) sind lose unsignedBigInteger+index (kein Cascade — eine gelöschte
 * Materialisierung darf die Skizze nicht mitreißen, ein referenziertes VK-Gericht nicht sperren).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Gruppen zuerst (dish_ideas.group_id → dish_idea_groups).
        if (! Schema::hasTable('foodalchemist_dish_idea_groups')) {
            Schema::create('foodalchemist_dish_idea_groups', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->index();
                // XOR-Owner (Service-Guard): Kapitel ODER Konzept.
                $table->foreignId('chapter_id')->nullable()
                    ->constrained('foodalchemist_foodbook_chapters')->cascadeOnDelete();
                $table->foreignId('concept_id')->nullable()
                    ->constrained('foodalchemist_concepts')->cascadeOnDelete();
                $table->string('name');                                   // → wird concept.name beim Go
                $table->decimal('target_price_pp', 10, 2)->nullable();    // €/Gast des Pakets
                $table->integer('position')->default(0);
                $table->unsignedBigInteger('materialized_concept_id')->nullable()->index(); // gesetzt beim Go
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('foodalchemist_dish_ideas')) {
            Schema::create('foodalchemist_dish_ideas', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->index();
                // XOR-Owner (Service-Guard): Kapitel ODER Konzept.
                $table->foreignId('chapter_id')->nullable()
                    ->constrained('foodalchemist_foodbook_chapters')->cascadeOnDelete();
                $table->foreignId('concept_id')->nullable()
                    ->constrained('foodalchemist_concepts')->cascadeOnDelete();
                $table->integer('position')->default(0);
                $table->string('title');                                  // Spec: titel (Content, EN-Schema)
                $table->text('description')->nullable();                  // Spec: beschreibung
                $table->unsignedBigInteger('sales_recipe_id')->nullable()->index(); // nur echte VK-Gerichte (Bestand-Ref)
                $table->string('target_form', 12)->default('einzel');     // einzel|paket (Model-Const); paket ⇒ group_id
                $table->foreignId('group_id')->nullable()
                    ->constrained('foodalchemist_dish_idea_groups')->nullOnDelete();
                $table->string('status', 20)->default('entwurf');         // entwurf|verworfen|freigegeben (Model-Const)
                $table->string('created_via')->nullable();
                $table->json('source_meta')->nullable();                  // Spec: quelle_meta (Original bleibt hier)
                $table->string('generation_status', 20)->nullable();      // null|queued|erstellt|fehlgeschlagen (L7/L8)
                $table->unsignedBigInteger('generated_recipe_id')->nullable()->index();
                $table->timestamp('materialized_at')->nullable();
                $table->json('materialized_ref')->nullable();             // {block_id} einzel / {concept_id,concept_slot_id} paket
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_dish_ideas');       // FK → groups zuerst weg
        Schema::dropIfExists('foodalchemist_dish_idea_groups');
    }
};
