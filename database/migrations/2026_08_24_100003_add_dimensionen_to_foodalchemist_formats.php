<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Format-Umbau F1: Format bekommt die Concept-Dimensionen (Facetten).
 * Spiegelt 2026_07_03_000006 (Concepter-Dimensionen) auf die Format-Ebene — dieselben
 * Vokabular-Tabellen (serving_forms/event_types/service_moments/seasons/target_groups),
 * nur neue FK-Spalten am Format + drei Format-Pivots. Kein neues Vokabular.
 * Additiv + guarded; nullable FKs (SQLite-tauglich per ADD COLUMN … REFERENCES).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_formats') && ! Schema::hasColumn('foodalchemist_formats', 'serving_form_id')) {
            Schema::table('foodalchemist_formats', function (Blueprint $table) {
                $table->foreignId('serving_form_id')->nullable()
                    ->constrained('foodalchemist_serving_forms')->nullOnDelete();
                $table->foreignId('event_type_id')->nullable()
                    ->constrained('foodalchemist_event_types')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('foodalchemist_format_service_moments')) {
            Schema::create('foodalchemist_format_service_moments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('format_id')->constrained('foodalchemist_formats')->cascadeOnDelete();
                $table->foreignId('service_moment_id')->constrained('foodalchemist_service_moments')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['format_id', 'service_moment_id'], 'fa_format_einsatzmomente_unique');
            });
        }

        if (! Schema::hasTable('foodalchemist_format_seasons')) {
            Schema::create('foodalchemist_format_seasons', function (Blueprint $table) {
                $table->id();
                $table->foreignId('format_id')->constrained('foodalchemist_formats')->cascadeOnDelete();
                $table->foreignId('season_id')->constrained('foodalchemist_seasons')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['format_id', 'season_id'], 'fa_format_saisons_unique');
            });
        }

        if (! Schema::hasTable('foodalchemist_format_target_groups')) {
            Schema::create('foodalchemist_format_target_groups', function (Blueprint $table) {
                $table->id();
                $table->foreignId('format_id')->constrained('foodalchemist_formats')->cascadeOnDelete();
                $table->foreignId('target_group_id')->constrained('foodalchemist_target_groups')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['format_id', 'target_group_id'], 'fa_format_target_groups_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_format_target_groups');
        Schema::dropIfExists('foodalchemist_format_seasons');
        Schema::dropIfExists('foodalchemist_format_service_moments');
        if (Schema::hasColumn('foodalchemist_formats', 'serving_form_id')) {
            Schema::table('foodalchemist_formats', function (Blueprint $table) {
                $table->dropConstrainedForeignId('serving_form_id');
                $table->dropConstrainedForeignId('event_type_id');
            });
        }
    }
};
