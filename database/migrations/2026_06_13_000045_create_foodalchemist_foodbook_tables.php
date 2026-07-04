<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M11-01 / Doc 15 §9.3 + D-8: Foodbook / Portfolio — die Angebots-/Menü-Mappe, die
 * Concepts (und einzelne Gerichte) zu einem versendbaren Kunden-Dokument komponiert.
 *
 *   GP → Rezept → Gericht → Concept → [ FOODBOOK ]
 *
 * - foodbook         = die Mappe (Kunde + Pax/Gästezahl leben HIER — D-CON-5, F-12).
 * - foodbook_kapitel = Kapitel-BAUM (self-FK parent_id), mit Preis pro Person + Versand-Snapshot.
 * - foodbook_block   = polymorphe Inhalts-Zeile, diskriminiert über `type`:
 *                      concept_ref · recipe_ref (Gericht) · header · text · spacer · image.
 *                      Wahl-Gruppen „A|B|C" über `variant_group_id` (auf BLOCK-Ebene — D-8).
 *
 * 07 §7: keine CHECK-Constraints (Enums im PHP-Layer), Index-Namen automatisch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_foodbooks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('code', 32)->nullable();
            $table->string('label');
            $table->integer('jahr')->nullable();
            $table->string('kunde')->nullable();                      // Kundenbindung (D-CON-5)
            $table->unsignedInteger('personen')->nullable();          // Pax/Gästezahl fürs Angebot (F-12)
            $table->string('status', 16)->default('draft');           // draft | aktiv | versendet | archiviert
            $table->text('description')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'code']);
        });

        Schema::create('foodalchemist_foodbook_chapters', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('foodbook_id')->constrained('foodalchemist_foodbooks')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('foodalchemist_foodbook_chapters')->nullOnDelete();
            $table->integer('position')->default(0);
            $table->string('titel');                                  // intern
            $table->string('konsumententitel')->nullable();           // Marketing (PDF)
            $table->string('claim')->nullable();
            $table->text('description')->nullable();
            $table->decimal('preis_pro_person', 10, 2)->nullable();
            $table->string('preis_modus', 12)->default('auto');       // auto (Σ Blöcke) | manuell
            $table->string('status', 16)->default('draft');           // draft | sent | archived
            $table->timestamp('snapshot_at')->nullable();             // Versand friert ein
            $table->json('snapshot_json')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('foodalchemist_foodbook_blocks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('chapter_id')->constrained('foodalchemist_foodbook_chapters')->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->string('type', 24);                               // concept_ref|recipe_ref|header|text|spacer|image
            $table->integer('ebene')->default(0);                     // 0–2 Einrückung
            $table->boolean('sichtbar')->default(true);               // Export-Filter
            $table->string('label')->nullable();                // intern
            $table->text('kundentext')->nullable();
            $table->text('interne_bemerkung')->nullable();
            $table->unsignedInteger('variant_group_id')->nullable();  // Wahl-Gruppe „A|B|C"
            // Typ-spezifisch (Service validiert Konsistenz)
            $table->foreignId('concept_id')->nullable()->constrained('foodalchemist_concepts')->nullOnDelete();
            $table->foreignId('vk_recipe_id')->nullable()->constrained('foodalchemist_recipes')->nullOnDelete();
            $table->decimal('quantity', 12, 3)->nullable();
            $table->foreignId('unit_vocab_id')->nullable()->constrained('foodalchemist_vocab_units')->nullOnDelete();
            $table->decimal('preis_wert', 10, 2)->nullable();         // header_frei_preis
            $table->string('preis_basis', 12)->nullable();            // person | pauschal
            $table->string('hoehe', 12)->nullable();                  // spacer: klein|mittel|gross
            $table->json('payload_json')->nullable();                 // image u. a.
            $table->string('header_source', 16)->nullable();          // KI-Lineage (GL-07)
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_foodbook_blocks');
        Schema::dropIfExists('foodalchemist_foodbook_chapters');
        Schema::dropIfExists('foodalchemist_foodbooks');
    }
};
