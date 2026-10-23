<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 86 — Handbuch (Standards zum Nachschlagen): ein eigener, eingefrorener Lese-Link je
 * Ausgabe-Dokument (Foodbook | Speisekarte | Speiseplan | Angebot), NEBEN dem Kundenlink der
 * Präsentation (Spec 43). Eigener Token, eigenes Ablaufdatum, eigener Widerruf. Bewusst eigene
 * Tabelle: die Präsentations-Tabelle ist je (Dokument, Betrieb) eindeutig und trägt Preise/Design.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_handbuecher')) {
            return;
        }
        Schema::create('foodalchemist_handbuecher', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->string('quelle_type', 32);                   // foodbook | speisekarte | speiseplan | angebot
            $table->unsignedBigInteger('quelle_id');
            $table->boolean('enabled')->default(true);           // Freigabe an/aus (Widerruf)
            $table->string('token', 64)->unique();
            $table->json('snapshot_json')->nullable();
            $table->json('settings_json')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            // Genau EIN Handbuch je Dokument.
            $table->unique(['quelle_type', 'quelle_id'], 'fa_handbuch_quelle_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_handbuecher');
    }
};
