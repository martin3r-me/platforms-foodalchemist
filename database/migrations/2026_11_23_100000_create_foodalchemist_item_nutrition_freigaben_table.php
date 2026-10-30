<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nährwert-Prüfung (Plan 29.10.2026): ein Mensch hat die Werte eines Artikels trotz hartem Befund freigegeben — der Artikel zählt
 * wieder in den GP-Nährwerten. Die Freigabe gilt nur für genau diese Werte (werte_hash); ändert Necta, Import oder Handpflege einen
 * Wert, wird neu geprüft. Eine Zeile je Artikel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_item_nutrition_freigaben', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('supplier_item_id')->unique();
            $table->string('werte_hash', 64);                          // SHA-256 über die geprüften Rohwerte
            $table->json('regeln')->nullable();                        // Befunde zum Zeitpunkt der Freigabe (Nachweis)
            $table->unsignedBigInteger('team_id')->nullable();         // Team, in dem freigegeben wurde
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('grund', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_item_nutrition_freigaben');
    }
};
