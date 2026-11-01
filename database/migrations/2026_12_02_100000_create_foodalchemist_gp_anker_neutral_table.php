<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GP-Aroma-Backfill (io-Probe 01.11.2026): „kein Aroma-Träger“ (neutral) ist eine gültige Antwort auf die Anker-Frage — es gibt im Inspire-
 * Vokabular keinen Anker `neutral`, also landete sie nirgends und das GP wurde bei JEDEM Lauf neu gefragt. Eine Zeile je GP hält fest:
 * geprüft, kein Anker. Mit Quelle und Konfidenz, damit ein Wiederholungslauf (neu_schaetzen = anker_neutral) sie wiederfindet.
 * Pairing-Regel: fehlt ein Anker, wird nie über Namensähnlichkeit geraten — neutral ist eine Endantwort.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_gp_anker_neutral', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('gp_id')->unique();
            $table->unsignedBigInteger('team_id')->nullable();         // Team, in dem geprüft wurde
            $table->string('source', 16);                               // ai | manual
            $table->decimal('ai_confidence', 4, 2)->nullable();
            $table->text('ai_reasoning')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_gp_anker_neutral');
    }
};
