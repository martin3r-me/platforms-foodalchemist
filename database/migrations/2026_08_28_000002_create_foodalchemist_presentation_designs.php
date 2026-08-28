<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 43 — Wiederverwendbare Präsentations-Designs (Ausgabe des visuellen Struktur-
 * Builders). layout_json = geordnete, gestylte Blockliste; tokens_json = globale
 * Design-Tokens (Palette/Typo/Abstände). Form-agnostisch (Foodbook/Speisekarte/
 * Speiseplan). team-gescopt via BelongsToTeamHierarchy: globale/Root-Designs
 * (team_id NULL) sind BHG-weite Haus-Designs, sichtbar in Kind-Teams, schreibbar
 * nur im eigenen Team. Die 3 Built-ins editorial/menu/kiosk sind Code-Seeds (keine
 * Zeilen), die als Startpunkt dienen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_presentation_designs')) {
            return;
        }
        Schema::create('foodalchemist_presentation_designs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('name');
            $table->string('base_slug', 16)->nullable();   // editorial|menu|kiosk = Ausgangs-Starter
            $table->json('layout_json')->nullable();        // geordnete Blockliste [{block_type, data_binding, style{}}]
            $table->json('tokens_json')->nullable();        // globale Design-Tokens
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_presentation_designs');
    }
};
