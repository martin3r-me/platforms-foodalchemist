<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §13 I — Paket „Grundeinstellungen": gebucht wird per KOPIE ins Oberteam (Kunden-Hauptteam), Unter-Teams erben über die Kette.
 *
 *  - `foodalchemist_team_pakete`: eine Zeile je gebuchtem Paket und Mandant (Status, Wer, Wann; Subjekt des Plattform-Protokolls).
 *  - `foodalchemist_paket_kopien`: Zuordnung Quelle → Kopie je Mandant und Tabelle. Das macht das zweite Buchen idempotent
 *    (nichts wird doppelt kopiert) und liefert die ID-Abbildung (z. B. `concept_categories.parent_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_team_pakete')) {
            Schema::create('foodalchemist_team_pakete', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('team_id');
                $table->string('paket', 40);
                $table->unsignedBigInteger('gebucht_von')->nullable();
                $table->timestamp('gebucht_am')->nullable();
                $table->timestamps();
                $table->unique(['team_id', 'paket'], 'fa_team_pakete_unique');
            });
        }
        if (! Schema::hasTable('foodalchemist_paket_kopien')) {
            Schema::create('foodalchemist_paket_kopien', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('team_id');
                $table->string('paket', 40);
                $table->string('tabelle', 80);
                $table->unsignedBigInteger('quell_id');
                $table->unsignedBigInteger('kopie_id');
                $table->string('art', 12)->default('kopie');   // kopie | vorhanden (gleichnamige Team-Zeile existierte, nicht überschrieben)
                $table->timestamps();
                $table->unique(['team_id', 'paket', 'tabelle', 'quell_id'], 'fa_paket_kopien_unique');
                $table->index(['team_id', 'tabelle'], 'fa_paket_kopien_team_tabelle_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_paket_kopien');
        Schema::dropIfExists('foodalchemist_team_pakete');
    }
};
