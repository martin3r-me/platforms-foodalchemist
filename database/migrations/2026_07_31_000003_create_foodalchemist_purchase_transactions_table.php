<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Einkauf E2 — Einkaufsjournal (echte Ist-Einkäufe), die Faktentabelle unter Spend,
 * erreichter Rückvergütungs-Stufe und Wareneinsatz-Optimierung.
 *
 * Bis hierher kannte FA nur einen KATALOG (Preise je LA) + einen Nutzungs-PROXY
 * (Rezept-Zutaten via Lead-LA). Es fehlte, WAS TATSÄCHLICH EINGEKAUFT wurde
 * (Menge × Preis × Datum × Lieferant). Eine Zeile = eine Ist-Einkaufsposition.
 *
 * ZWEI QUELLEN ins selbe Journal (`source`):
 *  - `necta_import` — Necta-Verbrauchsexport (bulk, regelmäßig, idempotent via `source_hash`)
 *  - `fa_order`     — in FA getätigter Einkauf: eine abgesendete/gelieferte Bestellschiene
 *                     projiziert ihre Zeilen hierher (`source_ref` = Order/Order-Line).
 *
 * BEWUSST team-scoped, KEIN customer_id — die Kunden-Dimension ist ein eigenes
 * Folge-Projekt. Als Vorkehrung wird die rohe Necta-Kostenstelle als
 * `source_scope_label` mitgespeichert → späterer Backfill auf customer_id ohne Rework.
 *
 * Matching an den Katalog (`supplier_item_id`) und ans Grundprodukt (`gp_id`) ist
 * NULLBAR: ungematchte Positionen bleiben im Journal (Roh-Text erhalten) und landen
 * in einer Review-Queue, statt verworfen zu werden. Index-Namen < 64 Zeichen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_purchase_transactions')) {
            return;
        }
        Schema::create('foodalchemist_purchase_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();

            // Lieferant: gematcht (FK) ODER nur als Roh-Text (unaufgelöst beim Import).
            $table->foreignId('supplier_id')->nullable()
                ->constrained('foodalchemist_suppliers')->nullOnDelete();
            $table->string('supplier_name_raw')->nullable();

            // Artikel → Katalog-LA + Grundprodukt (beide nullbar = noch ungematcht).
            $table->foreignId('supplier_item_id')->nullable()
                ->constrained('foodalchemist_supplier_items')->nullOnDelete();
            $table->foreignId('gp_id')->nullable()
                ->constrained('foodalchemist_gps')->nullOnDelete();
            $table->string('designation_raw');                       // roher Artikelname aus der Quelle

            // Mengengerüst (netto). unit_code = kg|l|Stk (Necta-Einheit).
            $table->string('unit_code', 16)->nullable();
            $table->decimal('qty', 14, 4)->nullable();
            $table->decimal('unit_price', 12, 4)->nullable();        // €/Einheit, netto
            $table->decimal('line_total', 14, 2)->nullable();        // €, netto (Menge × Preis)
            $table->date('purchased_at')->nullable();                // Liefer-/Buchungsdatum (fehlt in Altformaten)
            $table->string('commodity_group', 64)->nullable();       // Necta-Produktklasse (WG-Code/-Name)

            // Herkunft + Idempotenz.
            $table->string('source', 16)->default('necta_import');   // necta_import | fa_order
            $table->string('source_ref')->nullable();                // Order/Order-Line-Ref bei fa_order
            $table->string('source_scope_label')->nullable();        // rohe Necta-Kostenstelle (Kunden-Session)
            $table->string('import_batch_id')->nullable()->index();  // gruppiert einen Import-Lauf
            $table->string('source_hash', 64)->nullable();           // Zeilen-Identität für Dedup

            $table->timestamps();
            $table->softDeletes();

            // Leserichtung: Spend/Optimierung je (Team, Lieferant) über die Zeit.
            $table->index(['team_id', 'supplier_id', 'purchased_at'], 'fa_purchtx_supplier_time_idx');
            $table->index(['team_id', 'gp_id'], 'fa_purchtx_gp_idx');
            // Idempotenz: dieselbe Quell-Zeile nur einmal je Team (NULL-Hash = nicht dedupbar, erlaubt).
            $table->unique(['team_id', 'source_hash'], 'fa_purchtx_dedup_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_purchase_transactions');
    }
};
