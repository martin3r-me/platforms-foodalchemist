<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preise (02_DATENMODELL §A.1, Quelle prices, 221.591 Zeilen). Global (D1) — Team-Sichtbarkeit
 * (Rückvergütungs-Konditionen) ist D1-Restpunkt. Necta-`tenant_id` fällt weg (Artefakt).
 * Slice-Teilmenge: Promo-/Discount-Block (durchgängig ungenutzt, GL-11 A-3) folgt nur bei Bedarf.
 * Preis-Kategorisierung (GL-11 T1) passiert im Service, nicht als Spalte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_prices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('NULL = global (D1)');
            $table->unsignedBigInteger('legacy_id')->nullable()->unique()->comment('Quell-PK (prices.id)');
            $table->foreignId('supplier_item_id')->constrained('foodalchemist_supplier_items')->cascadeOnDelete();

            $table->string('status', 8)->nullable()->comment('Necta-Status-Code (GL-11 T1: 2=aktion, …)');
            $table->decimal('price', 12, 4)->nullable();
            $table->decimal('price_partial', 12, 4)->nullable()->comment('Anbruchpreis (GL-11)');
            $table->dateTime('valid_to')->nullable()->index();
            $table->dateTime('status_valid_from')->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->dateTime('change_date')->nullable();
            $table->dateTime('creation_date')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['supplier_item_id', 'valid_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_prices');
    }
};
