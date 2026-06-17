<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #380 DoD-5 — ein Angebot kann zusätzlich zu seinen ad-hoc-Menüs (Concepts mit
 * angebot_id) bestehende STANDARDISIERTE Katalog-Concepts REFERENZIEREN (ohne sie
 * zu besitzen). Pivot Angebot↔Concept (Katalog bleibt geteilt). Detach = Zeile löschen.
 *
 * Engine-agnostisch: schlichte indexierte Spalten (kein cross-modul-FK), unique zur
 * Vermeidung von Doppel-Referenzen (create-time, SQLite-safe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_angebot_concept', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedBigInteger('angebot_id')->index();
            $table->unsignedBigInteger('concept_id')->index();
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->unique(['angebot_id', 'concept_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_angebot_concept');
    }
};
