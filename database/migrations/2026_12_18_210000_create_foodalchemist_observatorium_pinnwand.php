<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Observatorium zentral (Dominique 07.11.2026): gemeinsame Team-Pinnwand je Mandant. Angepinnt werden Meldungen aus dem
 * (vom Master kuratierten) Observatorium oder eigene Ideen (Titel, Notiz, Link). Streng je Team — nie an Eltern/Kinder vererbt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_observatorium_pinnwand', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $t->unsignedBigInteger('eingang_id')->nullable()->index();
            $t->string('titel', 200)->nullable();
            $t->text('notiz')->nullable();
            $t->string('link', 500)->nullable();
            $t->unsignedBigInteger('erstellt_von')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['team_id', 'created_at'], 'fa_obs_pinnwand_team_zeit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_observatorium_pinnwand');
    }
};
