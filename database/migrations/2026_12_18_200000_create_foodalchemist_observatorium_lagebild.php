<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Observatorium zentral (Dominique 07.11.2026): wöchentliches Lagebild, das BHG.DIGITAL (Master) für alle Mandanten schreibt.
 * Geschrieben per MCP (foodalchemist.trend_lagebild.POST) von einem Claude-Läufer; jede Aussage trägt Belege (Eingangs-IDs),
 * Zahlen nur, wenn sie in den belegten Einträgen stehen. Ein Lagebild je Team und Zeitraum-Ende.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_observatorium_lagebild', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $t->date('zeitraum_von');
            $t->date('zeitraum_bis');
            $t->string('titel', 200);
            $t->string('kurzfazit', 600)->nullable();
            $t->json('aussagen');
            $t->json('impulse')->nullable();
            $t->unsignedSmallInteger('verworfen')->default(0);
            $t->string('anbieter', 60)->nullable();
            $t->string('modell', 80)->nullable();
            $t->unsignedBigInteger('erstellt_von')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['team_id', 'zeitraum_bis'], 'fa_obs_lagebild_team_bis');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_observatorium_lagebild');
    }
};
