<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Format-Modul (Phase A): Format = Marken-/Themen-Container EINE Ebene über dem
 * Concept (z. B. „CHEFS.CORNER – WORLD ON A PLATE"), der mehrere Zusammenstellungen
 * (Concepts = Editionen/Themenevents) bündelt und die Marketing-Identität trägt.
 * Kein eigener Preis — nur read-only Min–Max-Range über die Editionen.
 * team-eigen (BelongsToTeamHierarchy: sichtbar Kette aufwärts, editierbar = Besitzer).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_formats')) {
            return;
        }

        Schema::create('foodalchemist_formats', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('name');                          // intern (z. B. "CHEFS.CORNER – WORLD ON A PLATE")
            $table->string('consumer_name')->nullable();     // Marken-Zeile Kunde
            $table->string('claim')->nullable();             // Tagline ("WORLD ON A PLATE")
            $table->text('story')->nullable();               // Marken-Story (Marketing-Text)
            $table->string('origin', 16)->nullable();        // eigen | gruppe | kunde (Kunden-IP-Guard)
            $table->string('customer')->nullable();          // bei origin=kunde: Besitzer-Kunde (Reuse-Guard)
            $table->string('status', 16)->default('draft');  // draft | active | archiviert
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_formats');
    }
};
