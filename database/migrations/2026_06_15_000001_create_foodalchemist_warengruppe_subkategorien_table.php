<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #371: Verwaltete Sub-Kategorien je Warengruppe. Bisher waren Sub-Kategorien reiner
 * Freitext auf den GPs (`gps.sub_kategorie`) — sie entstanden erst, wenn ein GP den Wert
 * bekam, und ließen sich in den Einstellungen nicht ANLEGEN. Diese Tabelle ist die
 * verwaltete Werte-Liste (Dominique-Entscheid 2026-06-15): „WG fix, Sub-Kategorien als
 * verwaltete Liste". Die Übersicht merged verwaltete Einträge + vorhandene GP-Freitextwerte.
 *
 * Idempotent (hasTable), kurze, explizite Index-Namen (MySQL 64-Zeichen-Limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_warengruppe_subkategorien')) {
            return;
        }

        Schema::create('foodalchemist_warengruppe_subkategorien', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index('fa_wg_subkat_team_idx');
            $table->string('warengruppe_code', 16);
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('warengruppe_code', 'fa_wg_subkat_wg_idx');
            $table->unique(['team_id', 'warengruppe_code', 'name'], 'fa_wg_subkat_team_wg_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_warengruppe_subkategorien');
    }
};
