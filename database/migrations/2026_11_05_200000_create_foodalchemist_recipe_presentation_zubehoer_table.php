<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zubehör & Verpackung je Darreichung (Dominique 2026-10-16): das Schraubglas gehört zur Form „im Schraubglas“ des Gerichts, nicht zur
 * Zutatenliste. Eigene Kind-Tabelle, weil die Darreichungs-Deltas per Grenzregel E5 keine neuen Zutaten dürfen. Die Zeile zeigt auf ein
 * Non-Food-Grundprodukt (Warengruppe 16, im Service geprüft), Menge je EINHEIT der Darreichung (× unit_count). Wirkung nur auf den EK
 * der Darreichung (ek_portion), nie auf Allergene, Nährwerte, Profil oder Yield des Kernrezepts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_recipe_presentation_zubehoer')) {
            return;
        }
        Schema::create('foodalchemist_recipe_presentation_zubehoer', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->foreignId('presentation_id')->constrained('foodalchemist_recipe_presentations')->cascadeOnDelete();
            $table->foreignId('gp_id')->constrained('foodalchemist_gps')->restrictOnDelete();
            $table->decimal('quantity', 10, 3)->default(1)->comment('Stück je Einheit der Darreichung');
            $table->unsignedInteger('position')->default(0);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['presentation_id', 'gp_id'], 'fa_darreichung_zubehoer_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_recipe_presentation_zubehoer');
    }
};
