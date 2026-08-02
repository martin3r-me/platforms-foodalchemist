<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Speisekarte — dritte Ausgabeform neben Foodbook (Catering) und Speiseplan (GV).
 * Die Gastronomie-à-la-carte-Karte. Strukturell ein getrimmtes Foodbook:
 *
 *   GP → Rezept → Gericht → Concept → [ SPEISEKARTE ]
 *
 * - menu_card          = die Karte (freistehendes Dokument; Outlet optional).
 * - menu_card_section  = Rubrik-BAUM (self-FK parent_id) — z. B. Vorspeisen · Hauptgänge · Desserts,
 *                        Hauptgänge → Fleisch/Fisch/Vegetarisch als Unter-Rubrik.
 * - menu_card_item     = polymorphe Positions-Zeile, diskriminiert über `type`:
 *                        gericht_ref (Einzelgericht/Getränk) · menue_ref (Fix-Menü = Concept)
 *                        · header · text · spacer · image. Wahl-Gruppen über `variant_group_id`.
 *
 * Getränke/Wein: gericht_ref auf ein Getränke-Rezept (WG 15); Glas/Flasche = Darreichung
 * (presentation_id); Wein-Metadaten (Jahrgang/Region/Rebsorte) in payload_json.
 * Fix-Menü: menue_ref auf ein Concept; Gänge via WordingResolver::gerichtZeilen.
 *
 * 07 §7: keine CHECK-Constraints (Enums im PHP-Layer). Idempotent (hasTable/hasIndex) —
 * MySQL hat kein transaktionales DDL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_menu_cards')) {
            Schema::create('foodalchemist_menu_cards', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->string('code', 32)->nullable();
                $table->string('name');
                $table->string('status', 16)->default('entwurf');         // entwurf|aktiv|veroeffentlicht|archiviert
                // Anker (optional — freistehend wie Foodbook, Outlet nicht Pflicht)
                $table->foreignId('outlet_id')->nullable()->constrained('foodalchemist_outlets')->nullOnDelete();
                // Gastro-Spezifika
                $table->string('karten_typ', 20)->default('alacarte');    // alacarte|tageskarte|saisonkarte|getraenkekarte|weinkarte
                $table->date('gueltig_von')->nullable();
                $table->date('gueltig_bis')->nullable();
                $table->boolean('preis_anzeige_brutto')->default(true);   // Gastro zeigt i. d. R. Bruttopreise
                $table->text('description')->nullable();
                $table->text('note')->nullable();
                // Branding/CI (Spiegel Foodbook)
                $table->string('brand_color', 9)->default('#6d28d9');
                $table->string('band_color', 9)->nullable();
                $table->string('logo_path')->nullable();
                $table->string('cover_image_path')->nullable();
                $table->text('footer_text')->nullable();
                // Leitplanken (Spiegel Foodbook) — Kontext für KI-Wording
                $table->string('kundentyp')->nullable();
                $table->string('default_niveau', 20)->nullable();         // buergerlich|gehoben|fine_dining
                $table->string('default_convenience', 20)->nullable();    // from_scratch|teil_convenience|voll_convenience
                // Phase (Spiegel Foodbook/PhaseService)
                $table->string('phase', 16)->default('kontext')->index(); // kontext|struktur|befuellung|kalkulation|freigabe
                $table->text('phase_override_note')->nullable();
                $table->timestamp('phase_override_at')->nullable();
                // KI/Wording
                $table->foreignId('writing_style_id')->nullable()->constrained('foodalchemist_writing_styles')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['team_id', 'code']);
            });
        }

        if (! Schema::hasTable('foodalchemist_menu_card_sections')) {
            Schema::create('foodalchemist_menu_card_sections', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->foreignId('menu_card_id')->constrained('foodalchemist_menu_cards')->cascadeOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('foodalchemist_menu_card_sections')->nullOnDelete();
                $table->integer('position')->default(0);
                $table->string('title');                                  // intern
                $table->string('consumer_title')->nullable();             // Druck/Gast
                $table->string('claim')->nullable();
                $table->text('description')->nullable();
                $table->string('art', 16)->default('speisen');            // speisen|getraenke|menue|dessert|sonstiges
                $table->string('preis_anzeige', 8)->default('mit');       // mit|ohne
                $table->string('status', 16)->default('draft');
                $table->timestamp('snapshot_at')->nullable();
                $table->json('snapshot_json')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('foodalchemist_menu_card_items')) {
            Schema::create('foodalchemist_menu_card_items', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->foreignId('section_id')->constrained('foodalchemist_menu_card_sections')->cascadeOnDelete();
                $table->integer('position')->default(0);
                $table->string('type', 24);                               // gericht_ref|menue_ref|header|text|spacer|image
                $table->integer('level')->default(0);
                $table->boolean('visible')->default(true);
                $table->string('label')->nullable();                      // intern
                $table->text('consumer_text')->nullable();
                $table->text('interne_bemerkung')->nullable();
                $table->unsignedInteger('variant_group_id')->nullable();
                // Typ-spezifisch (Service validiert Konsistenz)
                $table->foreignId('sales_recipe_id')->nullable()->constrained('foodalchemist_recipes')->nullOnDelete();
                $table->foreignId('concept_id')->nullable()->constrained('foodalchemist_concepts')->nullOnDelete();
                $table->unsignedBigInteger('presentation_id')->nullable(); // loser Zeiger auf recipe_presentation (wie Foodbook-Block)
                $table->text('wording')->nullable();                       // Override (oberste Wording-Stufe)
                $table->string('price_mode', 12)->default('auto');        // auto | manuell
                $table->decimal('price_value', 10, 2)->nullable();
                $table->string('height', 12)->nullable();                  // spacer: klein|mittel|gross
                $table->json('payload_json')->nullable();                  // Wein-Metadaten, wording_overrides, image
                $table->string('header_source', 48)->nullable();           // KI-Lineage
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! $this->hasIndex('foodalchemist_menu_card_items', 'fa_speisekarte_item_section_pos_idx')) {
            Schema::table('foodalchemist_menu_card_items', function (Blueprint $table) {
                $table->index(['section_id', 'position'], 'fa_speisekarte_item_section_pos_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_menu_card_items');
        Schema::dropIfExists('foodalchemist_menu_card_sections');
        Schema::dropIfExists('foodalchemist_menu_cards');
    }

    private function hasIndex(string $tabelle, string $indexName): bool
    {
        if (! Schema::hasTable($tabelle)) {
            return false;
        }

        return collect(Schema::getIndexes($tabelle))
            ->pluck('name')
            ->contains($indexName);
    }
};
