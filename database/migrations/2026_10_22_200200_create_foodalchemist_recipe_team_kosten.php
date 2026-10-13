<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §12 C / Stufe 3b · Durchstich Schritt 3: Kosten je (Rezept-Zeile × Team), bei Bedarf gerechnet und
 * zwischengespeichert. recipe_id ist Arbeits- ODER Fassungs-Zeile. „veraltet" setzen die Auslöser (Teampreis,
 * Pin, Listenpreis); gelesen wird über KostenCacheService, der veraltete/fehlende Zeilen neu rechnet.
 * Kosten unvollständig (Variante 2, 13.10.): fehlende Zutaten + Anteil nach Anzahl und Masse, nie als volle Zahl.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_recipe_team_kosten')) {
            return;
        }
        Schema::create('foodalchemist_recipe_team_kosten', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('recipe_id');
            $table->unsignedBigInteger('team_id');
            $table->decimal('ek_summe_eur', 12, 2)->nullable()->comment('Summe der BEPREISTEN Zutaten (bei unvollständig = Teilsumme)');
            $table->decimal('ek_per_kg_eur', 12, 2)->nullable();
            $table->unsignedInteger('n_priced')->default(0);
            $table->unsignedInteger('n_total')->default(0);
            $table->decimal('masse_g_total', 14, 2)->nullable();
            $table->decimal('masse_g_fehlend', 14, 2)->nullable();
            $table->string('price_basis', 32)->nullable();
            $table->boolean('vollstaendig')->default(false);
            $table->string('unvollstaendig_grund', 40)->nullable();   // zutaten_ohne_preis | unterrezept_unvollstaendig
            $table->json('fehlende_zutaten')->nullable();              // [{pfad, name, gp_id, sub_id, masse_g}]
            $table->timestamp('preisstichtag')->nullable();
            $table->timestamp('berechnet_at')->nullable();
            $table->boolean('veraltet')->default(false);
            $table->timestamps();
            $table->unique(['recipe_id', 'team_id'], 'fa_rtk_eindeutig');
            $table->index(['team_id', 'veraltet'], 'fa_rtk_team_veraltet');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_recipe_team_kosten');
    }
};
