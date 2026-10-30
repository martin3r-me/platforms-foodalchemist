<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lieferanten-Link (Stufe 2a, Kurator 29.10.2026): persönlicher Link je Lieferant ohne Login. Der Token steht NIE im Klartext in der Datenbank (nur
 * sein Hash); der Link ist an genau EIN Team und EINEN Lieferanten gebunden, läuft ab und ist widerrufbar. Jedes Ereignis wird protokolliert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_lieferanten_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('supplier_id')->index();
            $table->char('token_hash', 64)->unique()->comment('sha256 des Tokens; der Klartext wird nur bei der Erzeugung angezeigt');
            $table->string('token_hinweis', 8)->comment('erste Zeichen zur Wiedererkennung in der Liste');
            $table->string('kontakt_name')->nullable();
            $table->string('kontakt_email')->nullable();
            $table->json('bereiche')->comment('preisliste | stammdaten | dokumente | formular');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamp('first_used_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('upload_count')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('foodalchemist_lieferanten_link_ereignisse', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->foreignId('link_id')->constrained('foodalchemist_lieferanten_links')->cascadeOnDelete();
            $table->string('art', 24)->comment('erzeugt | geoeffnet | upload | abgelehnt | widerrufen');
            $table->json('detail')->nullable();
            $table->char('ip_hash', 64)->nullable()->comment('sha256(IP + App-Schlüssel), nie die IP selbst');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['link_id', 'art']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_lieferanten_link_ereignisse');
        Schema::dropIfExists('foodalchemist_lieferanten_links');
    }
};
