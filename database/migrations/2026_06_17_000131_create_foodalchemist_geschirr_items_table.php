<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #388 Geschirr-Datenbank — Geschirr-Artikel (non-food, der konkrete Leih-Artikel).
 *
 * Spiegelt foodalchemist_supplier_items: gehört EINEM Geschirr-Leih-Lieferanten,
 * trägt den Leihpreis + Maße/Material direkt (KEIN GP-Layer, keine Allergene,
 * keine Preis-Historie — Leihpreis ist ein Feld). Die „Alternative je Gericht"
 * ist schlicht ein zweiter Geschirr-Artikel (z. B. vom anderen Leih-Caterer),
 * angedockt am concept_slot (Migration 000132).
 *
 * 07 §7: intra-Modul-FK via constrained() (wie concepter-Migration), engine-agnostisch.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_geschirr_items')) {
            return;
        }

        Schema::create('foodalchemist_geschirr_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('geschirr_supplier_id')->nullable()
                ->constrained('foodalchemist_geschirr_suppliers')->nullOnDelete();

            // Identität
            $table->string('bezeichnung')->index();
            $table->string('artikel_nr', 64)->nullable()->index();

            // Klassifikation (frei, kleine Vokabel-Anmutung — kein FK)
            $table->string('kategorie', 48)->nullable()->index();  // Teller|Schale|Glas|Besteck|Platte|Deko|…
            $table->string('material', 48)->nullable();            // Porzellan|Glas|Edelstahl|Holz|Schiefer|…
            $table->string('form', 32)->nullable();                // rund|eckig|oval|…
            $table->string('farbe', 48)->nullable();

            // Maße (alle nullable — je nach Geschirr-Typ relevant)
            $table->decimal('durchmesser_mm', 8, 1)->nullable();   // Teller/Schale
            $table->decimal('laenge_mm', 8, 1)->nullable();        // eckige Platte
            $table->decimal('breite_mm', 8, 1)->nullable();
            $table->decimal('hoehe_mm', 8, 1)->nullable();
            $table->decimal('volumen_ml', 8, 1)->nullable();       // Glas/Schale
            $table->decimal('gewicht_g', 8, 1)->nullable();

            // Leih-Konditionen (netto)
            $table->decimal('leihpreis', 10, 2)->nullable()->comment('Leihpreis netto je Einheit');
            $table->decimal('pfand', 10, 2)->nullable();
            $table->string('einheit', 16)->default('Stk');

            $table->text('note')->nullable();
            $table->boolean('is_inactive')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_geschirr_items');
    }
};
