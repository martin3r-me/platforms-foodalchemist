<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 85 (Dominique 19.10.): KI-Klasse je Arbeitsschritt einstellbar — global, gepflegt nur vom Admin im Master-Team.
 * Eine Zeile je Prompt-Key; `klasse` NULL (oder keine Zeile) = die Klasse aus dem Code (`foodalchemist.prompts.<key>.klasse`).
 * Jede Änderung geht über LogsActivity ins Aktivitätsprotokoll.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_ki_klassen_override')) {
            return;
        }
        Schema::create('foodalchemist_ki_klassen_override', function (Blueprint $t) {
            $t->id();
            $t->string('feature_key', 120)->unique();
            $t->string('klasse', 10)->nullable();
            $t->unsignedBigInteger('set_by')->nullable();
            $t->string('grund')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_ki_klassen_override');
    }
};
