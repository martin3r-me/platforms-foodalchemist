<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M-SENS / Recipe-Sensorik — KI-bewertetes GEGARTES Profil je Rezept (Basis + Gericht).
 *
 * Warum eigene Tabellen statt GP-Aggregat: der gegarte Tellergeschmack ist emergent aus
 * Zutaten + Methode (rohe Zwiebel ≠ Schmorzwiebel) — das schätzt eine KI, die das Rezept
 * liest, nicht der Roh-MAX über die Zutaten-GPs. Struktur spiegelt foodalchemist_gp_*-Sensorik
 * (gp_id → recipe_id) + source_hash für Skip-if-unchanged (Rezepte ändern sich, GPs nicht).
 * Roh-Aggregat bleibt Fallback; manueller Eintrag (quelle='manual') gewinnt.
 *
 * Engine-agnostisch/idempotent: hasTable-Guards, recipe_id als indizierte Spalte (kein
 * ALTER-add-FK, wie die GP-Tabellen — App-seitige Integrität), $table->id().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_recipe_geschmack_vektor')) {
            Schema::create('foodalchemist_recipe_geschmack_vektor', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('recipe_id');
                foreach (['suess', 'salzig', 'sauer', 'bitter', 'umami', 'fettig', 'scharf'] as $dim) {
                    $table->decimal($dim, 4, 2)->default(0);
                }
                $table->string('quelle')->nullable();        // ai | manual
                $table->decimal('ai_confidence', 4, 2)->nullable();
                $table->text('ai_begruendung')->nullable();
                $table->string('source_hash', 64)->nullable(); // sha256(name+zutaten+zubereitung) → Skip-if-unchanged
                $table->timestamps();
                $table->unique('recipe_id');
            });
        }

        if (! Schema::hasTable('foodalchemist_recipe_textur')) {
            Schema::create('foodalchemist_recipe_textur', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('recipe_id')->index();
                $table->unsignedBigInteger('textur_vocab_id');
                $table->decimal('intensitaet', 4, 2)->default(1);
                $table->string('quelle')->nullable();
                $table->timestamps();
                $table->unique(['recipe_id', 'textur_vocab_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_recipe_textur');
        Schema::dropIfExists('foodalchemist_recipe_geschmack_vektor');
    }
};
