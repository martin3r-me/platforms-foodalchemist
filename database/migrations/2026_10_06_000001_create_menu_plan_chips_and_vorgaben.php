<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 59: Speiseplan-Vorgaben — „mind. 2× vegan, höchstens 1× Schwein pro Woche“.
 *
 * - foodalchemist_menu_plan_chips: zentraler Prüf-Chip-Katalog je Team (Einstellungen ›
 *   Speiseplan-Chips). `kriterien` ist eine Liste ODER-verknüpfter Bedingungen:
 *   {"art":"diaet","key":"vegan|vegetarisch|fleisch|fisch|schwein|rind"} oder
 *   {"art":"hauptgruppe","id":<dish_main_group_id>}. default_min/default_max sind nur
 *   Vorschlagswerte beim Hinzufügen einer Vorgabe am Plan.
 *   Lösch-Schutz wie bei Posten (V-06): nur stilllegen (`is_active`), nie löschen — Pläne
 *   referenzieren den Chip per id in ihrem JSON, ein gelöschter Chip ließe die Vorgabe
 *   still ins Leere laufen.
 * - menu_plans.vorgaben: Liste {chip_id, mahlzeit|null, min|null, max|null} je Plan.
 *   Kein FK (JSON) — die App validiert beim Speichern (SpeiseplanVorgabenService).
 *
 * Additiv und nullable: bestehende Pläne haben keine Vorgaben.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_menu_plan_chips')) {
            Schema::create('foodalchemist_menu_plan_chips', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->index()
                    ->comment('NOT NULL — Prüf-Chips sind team-eigen, kein globaler Seed');
                $table->string('label');
                $table->json('kriterien')->nullable()
                    ->comment('ODER-Liste: {art:diaet,key} | {art:hauptgruppe,id}');
                $table->unsignedSmallInteger('default_min')->nullable();
                $table->unsignedSmallInteger('default_max')->nullable();
                $table->unsignedInteger('sort_order')->default(100);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['team_id', 'sort_order'], 'fa_menuplan_chips_team_sort_idx');
            });
        }

        if (! Schema::hasColumn('foodalchemist_menu_plans', 'vorgaben')) {
            Schema::table('foodalchemist_menu_plans', function (Blueprint $table) {
                $table->json('vorgaben')->nullable()->after('source_synced_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('foodalchemist_menu_plans', function (Blueprint $table) {
            $table->dropColumn('vorgaben');
        });
        Schema::dropIfExists('foodalchemist_menu_plan_chips');
    }
};
