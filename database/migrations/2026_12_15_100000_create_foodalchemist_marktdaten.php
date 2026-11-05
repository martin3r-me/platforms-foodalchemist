<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_marktdaten', function (Blueprint $t) {
            $t->id();
            $t->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $t->string('serie', 60);
            $t->json('punkte')->nullable();
            $t->timestamp('abgerufen_at')->nullable();
            $t->timestamp('versucht_at')->nullable();
            $t->string('letzter_fehler', 500)->nullable();
            $t->timestamps();
            $t->unique(['team_id', 'serie'], 'fa_marktdaten_team_serie');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_marktdaten');
    }
};
