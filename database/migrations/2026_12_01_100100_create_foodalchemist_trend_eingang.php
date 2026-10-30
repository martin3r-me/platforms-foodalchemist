<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 93 A · Eingang des Trendradars: eine Zeile je Artikel (Titel, Link, Quelle, Datum, gekürzter Teaser — nie der
 * Volltext). Dublettenschutz über die normalisierte URL: der DB-Riegel ist unique(team_id, url_hash), die Prüfung über
 * die ganze Teamfamilie macht der Service. Die Spalten der KI-Erschließung (Abschnitt B) liegen schon hier, leer.
 * Rein additiv: neue Tabelle, down() droppt nur sie.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_trend_eingang')) {
            return;
        }
        Schema::create('foodalchemist_trend_eingang', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('quelle_id')->nullable()->index();
            $table->string('url', 2048);
            $table->char('url_hash', 64);
            $table->string('titel', 500);
            $table->timestamp('veroeffentlicht_am')->nullable();
            $table->text('teaser')->nullable();
            // Abschnitt B (KI-Erschließung) — in A leer
            $table->text('zusammenfassung')->nullable();
            $table->string('thema', 190)->nullable();
            $table->string('kategorie', 32)->nullable();
            $table->json('sparten')->nullable();
            $table->string('tiefe', 16)->nullable();
            $table->boolean('relevant')->nullable();
            $table->text('begruendung')->nullable();
            $table->unsignedBigInteger('inspiration_id')->nullable()->index();
            $table->string('status', 16)->default('neu')->index();
            $table->string('eingereicht_von', 8)->default('abruf');
            $table->timestamp('zuletzt_gesehen_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'url_hash'], 'fa_trend_eingang_team_url_uq');
            $table->index('url_hash', 'fa_trend_eingang_url_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_trend_eingang');
    }
};
