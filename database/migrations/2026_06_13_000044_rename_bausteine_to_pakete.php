<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M10c+ (Dominique 2026-06-13): „Baustein" → „Paket" (bessere Beschreibung).
 * Benennt die in 000040 angelegten Concepter-Tabellen/Spalten um — die
 * historische Create-Migration bleibt unverändert (Standard-Migrations-Praxis).
 *   foodalchemist_bausteine        → foodalchemist_packages
 *   foodalchemist_baustein_gerichte → foodalchemist_package_dishes
 *   *.baustein_id                  → *.package_id (paket_gerichte + concept_slots)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_bausteine') && ! Schema::hasTable('foodalchemist_packages')) {
            Schema::rename('foodalchemist_bausteine', 'foodalchemist_packages');
        }
        if (Schema::hasTable('foodalchemist_baustein_gerichte') && ! Schema::hasTable('foodalchemist_package_dishes')) {
            Schema::rename('foodalchemist_baustein_gerichte', 'foodalchemist_package_dishes');
        }
        if (Schema::hasColumn('foodalchemist_package_dishes', 'baustein_id')) {
            Schema::table('foodalchemist_package_dishes', function (Blueprint $table) {
                $table->renameColumn('baustein_id', 'package_id');
            });
        }
        if (Schema::hasColumn('foodalchemist_concept_slots', 'baustein_id')) {
            Schema::table('foodalchemist_concept_slots', function (Blueprint $table) {
                $table->renameColumn('baustein_id', 'package_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_concept_slots', 'package_id')) {
            Schema::table('foodalchemist_concept_slots', function (Blueprint $table) {
                $table->renameColumn('package_id', 'baustein_id');
            });
        }
        if (Schema::hasColumn('foodalchemist_package_dishes', 'package_id')) {
            Schema::table('foodalchemist_package_dishes', function (Blueprint $table) {
                $table->renameColumn('package_id', 'baustein_id');
            });
        }
        if (Schema::hasTable('foodalchemist_package_dishes')) {
            Schema::rename('foodalchemist_package_dishes', 'foodalchemist_baustein_gerichte');
        }
        if (Schema::hasTable('foodalchemist_packages')) {
            Schema::rename('foodalchemist_packages', 'foodalchemist_bausteine');
        }
    }
};
