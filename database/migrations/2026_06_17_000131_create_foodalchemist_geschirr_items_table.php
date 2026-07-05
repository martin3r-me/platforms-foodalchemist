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
        if (Schema::hasTable('foodalchemist_tableware_items')) {
            return;
        }

        Schema::create('foodalchemist_tableware_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('tableware_supplier_id')->nullable()
                ->constrained('foodalchemist_tableware_suppliers')->nullOnDelete();

            // Identität
            $table->string('label')->index();
            $table->string('artikel_nr', 64)->nullable()->index();

            // Klassifikation (frei, kleine Vokabel-Anmutung — kein FK)
            $table->string('category', 48)->nullable()->index();  // Teller|Schale|Glas|Besteck|Platte|Deko|…
            $table->string('material', 48)->nullable();            // Porzellan|Glas|Edelstahl|Holz|Schiefer|…
            $table->string('form', 32)->nullable();                // rund|eckig|oval|…
            $table->string('color', 48)->nullable();

            // Maße (alle nullable — je nach Geschirr-Typ relevant)
            $table->decimal('diameter_mm', 8, 1)->nullable();   // Teller/Schale
            $table->decimal('length_mm', 8, 1)->nullable();        // eckige Platte
            $table->decimal('width_mm', 8, 1)->nullable();
            $table->decimal('height_mm', 8, 1)->nullable();
            $table->decimal('volumen_ml', 8, 1)->nullable();       // Glas/Schale
            $table->decimal('weight_g', 8, 1)->nullable();

            // Leih-Konditionen (netto)
            $table->decimal('rental_price', 10, 2)->nullable()->comment('Leihpreis netto je Einheit');
            $table->decimal('pfand', 10, 2)->nullable();
            $table->string('unit', 16)->default('Stk');

            $table->text('note')->nullable();
            $table->boolean('is_inactive')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_tableware_items');
    }
};
