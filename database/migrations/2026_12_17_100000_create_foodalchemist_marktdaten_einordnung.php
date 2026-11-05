<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KI-Einordnung „Was heißt das für Caterer/Gastro“ je Team und Werte-Stand (wie foodalchemist_marktdaten team-gebunden,
 * Kinder-Teams erben). werte_hash verhindert einen zweiten KI-Aufruf für unveränderte Zahlen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_marktdaten_einordnung', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $t->string('stand_monat', 7);
            $t->char('werte_hash', 64);
            $t->string('status', 16)->default('laeuft');
            $t->json('saetze')->nullable();
            $t->unsignedSmallInteger('verworfen')->default(0);
            $t->string('hinweis', 300)->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['team_id', 'werte_hash'], 'fa_markt_einordnung_team_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_marktdaten_einordnung');
    }
};
