<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M10c+ (Dominique 2026-06-13): „Baustein" → „Paket" (bessere Beschreibung).
 * Benennt die in 000040 angelegten Concepter-Tabellen/Spalten um — die
 * historische Create-Migration bleibt unverändert (Standard-Migrations-Praxis).
 *   foodalchemist_bausteine        → foodalchemist_pakete
 *   foodalchemist_baustein_gerichte → foodalchemist_paket_gerichte
 *   *.baustein_id                  → *.paket_id (paket_gerichte + concept_slots)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_bausteine') && ! Schema::hasTable('foodalchemist_pakete')) {
            Schema::rename('foodalchemist_bausteine', 'foodalchemist_pakete');
        }
        if (Schema::hasTable('foodalchemist_baustein_gerichte') && ! Schema::hasTable('foodalchemist_paket_gerichte')) {
            Schema::rename('foodalchemist_baustein_gerichte', 'foodalchemist_paket_gerichte');
        }
        if (Schema::hasColumn('foodalchemist_paket_gerichte', 'baustein_id')) {
            Schema::table('foodalchemist_paket_gerichte', function (Blueprint $table) {
                $table->renameColumn('baustein_id', 'paket_id');
            });
        }
        if (Schema::hasColumn('foodalchemist_concept_slots', 'baustein_id')) {
            Schema::table('foodalchemist_concept_slots', function (Blueprint $table) {
                $table->renameColumn('baustein_id', 'paket_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_concept_slots', 'paket_id')) {
            Schema::table('foodalchemist_concept_slots', function (Blueprint $table) {
                $table->renameColumn('paket_id', 'baustein_id');
            });
        }
        if (Schema::hasColumn('foodalchemist_paket_gerichte', 'paket_id')) {
            Schema::table('foodalchemist_paket_gerichte', function (Blueprint $table) {
                $table->renameColumn('paket_id', 'baustein_id');
            });
        }
        if (Schema::hasTable('foodalchemist_paket_gerichte')) {
            Schema::rename('foodalchemist_paket_gerichte', 'foodalchemist_baustein_gerichte');
        }
        if (Schema::hasTable('foodalchemist_pakete')) {
            Schema::rename('foodalchemist_pakete', 'foodalchemist_bausteine');
        }
    }
};
