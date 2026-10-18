<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tageskurse der EZB für die KI-Kosten (Dominique 18.10.2026): je EZB-Datum eine Zeile (Historie). Global, kein Team.
 * ezb_kurs = USD je 1 EUR, wie die EZB veröffentlicht; usd_eur = 1 / ezb_kurs (damit rechnet AiCostCalculator).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_wechselkurse')) {
            return;
        }
        Schema::create('foodalchemist_wechselkurse', function (Blueprint $table) {
            $table->id();
            $table->date('datum')->comment('EZB-Referenzdatum');
            $table->string('waehrung', 3)->default('USD');
            $table->decimal('ezb_kurs', 12, 6)->comment('USD je 1 EUR (EZB)');
            $table->decimal('usd_eur', 12, 8)->comment('EUR je 1 USD = 1 / ezb_kurs');
            $table->string('quelle', 16)->default('ezb');
            $table->timestamps();
            $table->unique(['datum', 'waehrung'], 'fa_wechselkurse_datum_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_wechselkurse');
    }
};
