<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KI-Wording im Speiseplan (wie Speisekarte): Schreibstil am Plan, Name je Eintrag.
 * `wording` leer = Wording-Kette des Gerichts (sales_wording_standard → Name).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_menu_plans', 'writing_style_id')) {
            Schema::table('foodalchemist_menu_plans', function (Blueprint $table) {
                $table->foreignId('writing_style_id')->nullable()
                    ->constrained('foodalchemist_writing_styles')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('foodalchemist_menu_plan_entries', 'wording')) {
            Schema::table('foodalchemist_menu_plan_entries', function (Blueprint $table) {
                $table->string('wording', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_menu_plan_entries', 'wording')) {
            Schema::table('foodalchemist_menu_plan_entries', function (Blueprint $table) {
                $table->dropColumn('wording');
            });
        }
        if (Schema::hasColumn('foodalchemist_menu_plans', 'writing_style_id')) {
            Schema::table('foodalchemist_menu_plans', function (Blueprint $table) {
                $table->dropConstrainedForeignId('writing_style_id');
            });
        }
    }
};
