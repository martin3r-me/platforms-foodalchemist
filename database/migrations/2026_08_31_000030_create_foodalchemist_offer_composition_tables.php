<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Angebot-Aufbau (#380 Composer) — das Angebot bekommt eine EIGENE, isolierte
 * Kompositions-Ebene NACH dem Foodbook-Vorbild (Kapitel → Blöcke), aber in eigenen
 * Tabellen (kein Eingriff in die geteilten Foodbook-Tabellen). Dominique-Entscheid
 * 2026-08-31: „eigene Angebots-Tabellen" (max. Isolation).
 *
 *   Angebot → offer_chapter (Kapitel/Rubrik, Baum via parent_id) → offer_block
 *
 * Kapitel:
 *  - normales Kapitel  = Titel + Blöcke (concept_ref / header / text / image …)
 *  - Format-Kapitel    = `format_id` gesetzt ⇒ rendert die Editionen des Formats
 *                        LIVE (spiegelt FoodAlchemistFoodbookKapitel::istFormatKapitel).
 *
 * Block (diskriminiert über `type`):
 *  - concept_ref  → Concept (live, wie Foodbook concept_ref)
 *  - recipe_ref   → einzelnes Gericht (VK-Rezept) — Parität, im Picker vorerst aus
 *  - header       → neutrale Rubrik-Überschrift
 *  - header_preis → Überschrift mit Pauschal-/Personenpreis (price_value/price_basis)
 *  - text/spacer/image → Struktur
 *
 * 07 §7: keine CHECK-Constraints (Enums im PHP-Layer). format_id/serving_form_id/
 * presentation_id sind LOSE Zeiger (unsignedBigInteger, keine harte FK) — Muster wie
 * bei Foodbook-Block.presentation_id / Kapitel.outlet_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_offer_chapters', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('offer_id')->constrained('foodalchemist_offers')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('foodalchemist_offer_chapters')->nullOnDelete();
            $table->integer('position')->default(0);
            $table->string('title');                                 // intern
            $table->string('consumer_title')->nullable();            // Kundensicht (Angebots-Dokument)
            $table->string('claim')->nullable();
            $table->text('description')->nullable();                 // Hinführung / Story
            // Format-Kapitel (live): gesetzt ⇒ Kapitel rendert die Editionen des Formats.
            $table->unsignedBigInteger('format_id')->nullable()->index();
            // Darreichungs-Kontext (Parität zu Foodbook-Kapitel; DarreichungResolver-Scharnier).
            $table->unsignedBigInteger('serving_form_id')->nullable()->index();
            $table->decimal('price_per_person', 10, 2)->nullable();  // Cache (Σ Blöcke bzw. manuell)
            $table->string('price_mode', 12)->default('auto');       // auto (Σ Blöcke) | manuell
            // Format-Kapitel: wie die Editionen bepreist werden — additiv (Tages-VA, Σ) | alternativen (Showcase-Range).
            $table->string('format_price_mode', 12)->default('additiv');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('foodalchemist_offer_blocks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('chapter_id')->constrained('foodalchemist_offer_chapters')->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->string('type', 24);                              // concept_ref|recipe_ref|header|header_preis|text|spacer|image
            $table->integer('level')->default(0);                    // 0–2 Einrückung
            $table->boolean('visible')->default(true);               // Export-Filter
            $table->string('label')->nullable();                     // intern
            $table->string('wording')->nullable();                   // Kundensicht-Override (Concept-Slot-Analog)
            $table->text('customer_text')->nullable();
            $table->text('interne_bemerkung')->nullable();
            // Typ-spezifisch (Service validiert Konsistenz)
            $table->foreignId('concept_id')->nullable()->constrained('foodalchemist_concepts')->nullOnDelete();
            $table->foreignId('sales_recipe_id')->nullable()->constrained('foodalchemist_recipes')->nullOnDelete();
            $table->decimal('quantity', 12, 3)->nullable();
            $table->foreignId('unit_vocab_id')->nullable()->constrained('foodalchemist_vocab_units')->nullOnDelete();
            $table->unsignedBigInteger('presentation_id')->nullable();  // loser Darreichungs-Override
            $table->decimal('price_value', 10, 2)->nullable();          // header_preis
            $table->string('price_basis', 12)->nullable();              // person | pauschal
            $table->string('height', 12)->nullable();                   // spacer: klein|mittel|gross
            $table->json('payload_json')->nullable();                   // image u. a.
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_offer_blocks');
        Schema::dropIfExists('foodalchemist_offer_chapters');
    }
};
