<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * „Mein Briefing“ mit gespeichertem Profil (Dominique 07.11.2026): je Person und Arbeits-Team Branchen, Region, Zeitraum
 * und Themen. Ohne Profil bleibt das Briefing die Kurzfassung der aktuellen Filter. Keine Küchen-DNA-Kopplung (bewusst).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_observatorium_briefing_profil')) {
            return;
        }
        Schema::create('foodalchemist_observatorium_briefing_profil', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->json('branchen')->nullable();
            $t->string('region', 8)->nullable();
            $t->string('tage', 8)->default('7');
            $t->json('themen')->nullable();
            $t->timestamps();
            $t->unique(['team_id', 'user_id'], 'fa_obs_briefing_profil_eindeutig');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_observatorium_briefing_profil');
    }
};
