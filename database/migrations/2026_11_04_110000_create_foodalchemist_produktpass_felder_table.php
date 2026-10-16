<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marker „dieses Feld stammt aus dem Produktpass": Quelle je Feld (Anzeige „produktpass_ki"), Schutz vor dem
 * Necta-Abgleich (gilt wie Handpflege; „FA ≠ necta_stand" allein reicht nicht — kein Stand, Pass = Necta-Wert, Abwesenheit-erklärt).
 * `wert_norm` ist der geschriebene Wert; der Schutz greift nur, solange der FA-Wert noch dem entspricht.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_produktpass_felder')) {
            return;
        }
        Schema::create('foodalchemist_produktpass_felder', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_item_id')->constrained('foodalchemist_supplier_items')->cascadeOnDelete();
            $table->foreignId('produktpass_id')->constrained('foodalchemist_produktpaesse')->cascadeOnDelete();
            $table->string('objekt', 32)->comment('supplier_item | item_declaration | item_nutritional | item_allergen');
            $table->string('feld', 64);
            $table->text('wert_norm')->nullable();
            $table->string('quelle', 24)->default('produktpass_ki');
            $table->boolean('abgeleitet')->default(false)->comment('z. B. Natrium aus Salz / 2,5');
            $table->decimal('konfidenz', 4, 3)->nullable();
            $table->timestamps();
            $table->unique(['supplier_item_id', 'objekt', 'feld'], 'fa_produktpass_feld_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_produktpass_felder');
    }
};
