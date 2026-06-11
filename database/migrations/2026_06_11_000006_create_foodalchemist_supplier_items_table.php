<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lieferantenartikel-Katalog (02_DATENMODELL §A.1, Quelle supplier_items, 264.515 Zeilen). Global (D1).
 * Slice-Teilmenge der 64 Quell-Spalten (Match-/Preis-/Anzeige-relevant); Label-/NUTS-Block folgt im Voll-Port P2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_supplier_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('NULL = global (D1)');
            $table->unsignedBigInteger('legacy_id')->nullable()->unique()->comment('Quell-PK (supplier_items.id) — Necta-Artikel-ID');
            $table->foreignId('supplier_id')->constrained('foodalchemist_suppliers')->cascadeOnDelete();

            $table->string('article_number', 64)->nullable()->index();
            $table->string('designation')->index()->comment('Suche via LOWER() (GL-05-Match-Pfad)');
            $table->string('marketing_name')->nullable();
            $table->string('regulated_name')->nullable();
            $table->string('brand')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('origin')->nullable();

            $table->string('packaging_unit', 32)->nullable();
            $table->string('ordering_unit', 32)->nullable();
            $table->decimal('qty_ordering_per_packaging', 10, 3)->nullable();
            $table->decimal('qty', 10, 3)->nullable()->comment('Gebinde-Menge — NULL-Fälle sind die GL-03-A-2-Preisfalle!');

            $table->string('ean_packaging', 32)->nullable()->index();
            $table->string('ean_ordering', 32)->nullable()->index();

            $table->boolean('is_organic')->nullable();
            $table->boolean('is_vegan')->nullable();
            $table->boolean('is_vegetarian')->nullable();
            $table->boolean('is_alcohol')->nullable();
            $table->boolean('is_discontinued')->default(false)->index();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_supplier_items');
    }
};
