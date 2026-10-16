<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Non-Food-Kennzeichnung am Lieferantenartikel (Dominique 2026-10-16).
 *
 * Bewusst eine EIGENE Tabelle und nicht `structures.is_food`: dieses Feld heisst "der Klassifikator hat eine Food-Struktur
 * bestimmt", ist nullable und wird von ClassifyLaJob/ImportSlice ueberschrieben. Hier steht ein dreiwertiges Urteil
 * (NULL = unklar, 1 = Non-Food, 0 = Food) MIT Herkunft (regel | ki | manuell), Konfidenz und Pruefstatus. Der Necta-Abgleich
 * schreibt nur Mapper-Felder und beruehrt die Tabelle nicht. Ohne Zeile gilt der Artikel als Food.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_supplier_item_nonfood', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('Team des Artikels (Katalog-Besitzer)');
            $table->foreignId('supplier_item_id')->unique()->constrained('foodalchemist_supplier_items')->cascadeOnDelete();
            $table->boolean('ist_nonfood')->nullable()->comment('NULL = unklar, 1 = Non-Food, 0 = Food (entschieden)');
            $table->string('quelle', 16)->default('regel')->comment('regel | ki | manuell — manuell wird nie ueberschrieben');
            $table->decimal('konfidenz', 4, 3)->nullable();
            $table->string('begruendung')->nullable();
            $table->string('subkategorie', 80)->nullable();
            $table->boolean('pruefung_offen')->default(false)->index();
            $table->timestamp('ki_versucht_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['ist_nonfood', 'pruefung_offen'], 'fa_sinf_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_supplier_item_nonfood');
    }
};
