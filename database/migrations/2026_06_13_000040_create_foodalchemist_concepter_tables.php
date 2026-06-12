<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M10-01 / 15_MASTERPLAN_VISION §M10: Concepter-Fundament — die Kompositions-
 * Ebene zwischen Gericht (VK-Rezept) und Foodbook.
 *
 *   GP → Rezept → Gericht (VK) → [ CONCEPT ] → Foodbook
 *
 * - CONCEPT  = Slot-Gerüst (z. B. „Grill-Buffet"): mehrere rollen-besetzte Slots.
 * - SLOT     = Rolle (Vorspeise/Hauptgang/Dessert …), gefüllt mit EINEM von:
 *                · BAUSTEIN  (Referenz auf ein bepreistes Bündel — austauschbar)
 *                · Gericht   (fest gesetztes VK-Rezept, Fixkosten)
 * - BAUSTEIN = bepreistes Bündel MEHRERER Gerichte, das eine Rolle füllt
 *              (Baukasten für den Verkäufer; Beispiel „Salad Wall" 4,50 €/P).
 *              Trägt einen GESPEICHERTEN Per-Person-Preis, damit ein Tausch im
 *              Concept nur die Differenz rechnet — kein Kaskaden-Recompute.
 *
 * Entscheide (Dominique 2026-06-13, Doc 15 §5): Rollen-Vokabular frei (rolle als
 * String + vocab_rollen als Autocomplete/Pflege-Liste); KEINE Concept-in-Concept-
 * Verschachtelung; Kundenbindung erst am Foodbook → Concepts/Bausteine sind team-
 * eigene Baukasten-Teile (BelongsToTeamHierarchy: sichtbar Kette aufwärts).
 *
 * 07 §7-Konvention: keine CHECK-Constraints (Enums im PHP-Layer), Index-Namen
 * automatisch, engine-agnostisch.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Freies Rollen-Vokabular (Autocomplete + Pflege; rolle wird als String
        // an Slot/Baustein gehalten, daher kein harter FK — „frei", D-CON-2).
        Schema::create('foodalchemist_vocab_rollen', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('slug');
            $table->string('name');
            $table->string('gruppe')->nullable();
            $table->integer('sort_order')->default(100);
            $table->boolean('is_inactive')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'slug']);
        });

        // Baustein = bepreistes Bündel mehrerer Gerichte für EINE Rolle
        Schema::create('foodalchemist_bausteine', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('name');
            $table->string('rolle')->nullable()->index();             // frei; nur gleiche Rolle ist tauschbar (M13)
            $table->string('niveau', 16)->nullable();                 // haute|gehoben|klassisch (Tag)
            // Gespeicherter Per-Person-Preis (Einzelpreis) — Concept summiert NUR diesen
            $table->decimal('preis_pro_person', 10, 2)->nullable();
            $table->decimal('ek_pro_person', 10, 4)->nullable();      // Wareneinsatz/Person (Cache)
            $table->decimal('wareneinsatz_prozent', 5, 2)->nullable();// W% = EK/VK (Cache)
            $table->string('preis_modus', 12)->default('manuell');    // manuell | auto (aus Gerichten)
            $table->timestamp('preis_berechnet_am')->nullable();
            $table->boolean('preis_stale')->default(false);           // GP-Preis-Änderung → neu rechnen (GL-02-Muster)
            $table->text('beschreibung')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_inactive')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        // Die Gerichte (VK-Rezepte) IM Baustein
        Schema::create('foodalchemist_baustein_gerichte', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('baustein_id')->constrained('foodalchemist_bausteine')->cascadeOnDelete();
            $table->foreignId('vk_recipe_id')->constrained('foodalchemist_recipes')->cascadeOnDelete();
            $table->decimal('menge', 12, 3)->nullable();              // optionale Portionsangabe
            $table->foreignId('einheit_vocab_id')->nullable()->constrained('foodalchemist_vocab_einheiten')->nullOnDelete();
            $table->integer('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['baustein_id', 'vk_recipe_id']);
        });

        // Concept = die ganze verkäufliche Komposition über mehrere Rollen
        Schema::create('foodalchemist_concepts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('name');
            $table->string('anlass')->nullable();                     // Anlass-Tag
            $table->string('niveau', 16)->nullable();                 // haute|gehoben|klassisch
            $table->string('status', 16)->default('draft');           // draft|aktiv|archiviert
            $table->boolean('is_vorlage')->default(false)->index();   // Vorlage = gespeichertes Slot-Gerüst
            $table->foreignId('vorlage_quelle_id')->nullable()        // woher geforkt (Lineage, optional)
                ->constrained('foodalchemist_concepts')->nullOnDelete();
            $table->decimal('preis_pro_person_cache', 10, 2)->nullable(); // Σ Slot-Preise (optionaler Cache)
            $table->text('beschreibung')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Slot = Rolle im Concept, gefüllt mit GENAU EINEM: Baustein ODER festes Gericht
        Schema::create('foodalchemist_concept_slots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('concept_id')->constrained('foodalchemist_concepts')->cascadeOnDelete();
            $table->string('rolle')->nullable();                      // Rolle dieses Slots (frei)
            $table->string('titel')->nullable();                      // Anzeige-Label (optional)
            $table->integer('position')->default(0);
            $table->boolean('is_pflicht')->default(true);             // Pflicht vs. optional
            // Befüllung: genau EINES gesetzt (Service-validiert — Doc 15 §M10)
            $table->foreignId('baustein_id')->nullable()->constrained('foodalchemist_bausteine')->nullOnDelete();
            $table->foreignId('vk_recipe_id')->nullable()->constrained('foodalchemist_recipes')->nullOnDelete();
            $table->decimal('menge', 12, 3)->nullable();              // nur bei festem Gericht relevant
            $table->foreignId('einheit_vocab_id')->nullable()->constrained('foodalchemist_vocab_einheiten')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_concept_slots');
        Schema::dropIfExists('foodalchemist_concepts');
        Schema::dropIfExists('foodalchemist_baustein_gerichte');
        Schema::dropIfExists('foodalchemist_bausteine');
        Schema::dropIfExists('foodalchemist_vocab_rollen');
    }
};
