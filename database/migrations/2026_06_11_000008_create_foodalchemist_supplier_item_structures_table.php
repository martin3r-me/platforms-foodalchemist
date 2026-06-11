<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Strukturierte LA-Schicht (02_DATENMODELL §A.2, Quelle wawi_la_structured, 9.803 Zeilen) — Kern-IP:
 * pro Lieferantenartikel die kuratierte Klassifikation + GP-Zuordnung (GL-05).
 * `needs_review` wird im Ziel First-Class-Review-Queue (V-10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_supplier_item_structures', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('NULL = global (D1)');
            $table->unsignedBigInteger('legacy_id')->nullable()->unique()->comment('Quell-PK (= wawi_la_structured.supplier_item_id)');
            $table->foreignId('supplier_item_id')->unique()->constrained('foodalchemist_supplier_items')->cascadeOnDelete();
            $table->foreignId('gp_id')->nullable()->constrained('foodalchemist_gps')->nullOnDelete();

            $table->boolean('ist_lebensmittel')->nullable();
            $table->string('ausschluss_grund')->nullable();
            $table->string('hauptzutat_slug')->nullable()->index();
            $table->string('hauptzutat_display')->nullable();
            $table->decimal('hauptzutat_konfidenz', 4, 3)->nullable();
            $table->boolean('ist_aroma_haupttraeger')->nullable();
            $table->text('aroma_zutaten_slugs')->nullable()->comment('JSON-Array (Voll-Port: jsonb)');
            $table->decimal('aroma_zutaten_konfidenz', 4, 3)->nullable();
            $table->string('verarbeitung')->nullable();
            $table->decimal('verarbeitung_konfidenz', 4, 3)->nullable();
            $table->string('form')->nullable();
            $table->string('groesse')->nullable();
            $table->string('convenience_host')->nullable();
            $table->boolean('ist_bio')->nullable();
            $table->boolean('ist_halal')->nullable();
            $table->boolean('ist_vegan')->nullable();
            $table->string('warengruppe_vorschlag')->nullable();
            $table->decimal('warengruppe_konfidenz', 4, 3)->nullable();
            $table->string('gp_key')->nullable();
            $table->string('gp_name_derived')->nullable();
            $table->string('zustand', 16)->nullable();
            $table->string('klassifikator', 64)->nullable()->comment('technische Herkunft (GL-05 A1)');
            $table->string('klassifikator_version', 32)->nullable();
            $table->dateTime('klassifiziert_am')->nullable();
            $table->boolean('needs_review')->default(false)->index()->comment('Review-Queue V-10 (Seed: 597 offen)');
            $table->string('review_grund')->nullable();
            $table->text('ai_begruendung')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_supplier_item_structures');
    }
};
