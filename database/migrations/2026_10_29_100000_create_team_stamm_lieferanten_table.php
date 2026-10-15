<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 (Entscheid Dominique 15.10.) — Lieferanten freischalten je Mandant.
 *
 * Auf dem Server liegt der Stamm global (team_id NULL, stamm_class = stamm_paket). Ein Mandant mit gebuchtem Stamm-Paket sieht NICHT mehr alle
 * Lieferanten, sondern nur die hier freigeschalteten — samt ihrer Artikel, Preise, Allergene, Deklarationen und Nährwerte. Eine Zeile je
 * Mandant (Haupt-Team) und Lieferant; Unter-Teams erben die Auswahl wie das Paket. Standard bei neuer Buchung: keine Zeile = keine Lieferanten.
 * Das Master-Team sieht immer alle. Bewusst ohne Fremdschlüssel (SQLite-Neuaufbau, Lieferanten werden nicht hart gelöscht).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_team_stamm_lieferanten')) {
            return;
        }
        Schema::create('foodalchemist_team_stamm_lieferanten', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('freigegeben_von')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'supplier_id'], 'fa_team_stamm_lieferanten_unique');
            $table->index('supplier_id', 'fa_team_stamm_lieferanten_supplier_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_team_stamm_lieferanten');
    }
};
