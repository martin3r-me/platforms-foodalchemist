<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §12 B3 / Durchstich Schritt 4b: Übernahme einer neueren Fassung in ein eingefrorenes Angebot. Je Übernahme eine Zeile mit Person und Zeitpunkt
 * der Bestätigung (nur Rolle Freigeben bei Allergen-Änderung, Entscheidung Dominique 13.10.) und dem Allergen-Diff, der bestätigt wurde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_angebot_uebernahmen', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('angebot_id')->index();
            $table->unsignedBigInteger('einfrier_zeile_id')->nullable();
            $table->unsignedBigInteger('familie_id');
            $table->unsignedBigInteger('von_fassung_id');
            $table->unsignedBigInteger('nach_fassung_id');
            $table->boolean('allergen_geaendert')->default(false);
            $table->boolean('allergen_bestaetigt')->default(false);
            $table->unsignedBigInteger('bestaetigt_von')->nullable();
            $table->timestamp('bestaetigt_at')->nullable();
            $table->json('diff')->nullable();
            $table->json('betroffene_ausgaben')->nullable();
            $table->json('umgehaengt')->nullable()->comment('Welche Verweise auf die neue Fassung umgestellt wurden');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_angebot_uebernahmen');
    }
};
