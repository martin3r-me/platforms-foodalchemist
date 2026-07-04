<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M-K6 / Doc 16 §10.2: Fixkosten-Erfassung zum Ableiten der Gemeinkosten-Zuschläge.
 * Jede Fixkosten-Zeile ist einem GK-Block zugeordnet (block_key); der abgeleitete
 * Zuschlag-% = Σ Fixkosten(Block, monatlich) ÷ Bezugsbasis(Block-Basis) × 100.
 * Bezugsbasen (erwarteter WE/FEK/HK je Periode) als JSON am Team-Setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_fixed_costs')) {
            Schema::create('foodalchemist_fixed_costs', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('team_id')->nullable()->index();
                $table->string('label');
                $table->decimal('betrag', 12, 2)->default(0);
                $table->string('periode', 12)->default('monatlich');   // monatlich | jaehrlich
                $table->string('block_key')->index();                  // Zuordnung zu einem GK-Block
                $table->text('note')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasColumn('foodalchemist_team_settings', 'calculation_reference_bases')) {
            Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
                $table->json('calculation_reference_bases')->nullable();   // {mek, fek, hk, periode}
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_team_settings', 'calculation_reference_bases')) {
            Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
                $table->dropColumn('calculation_reference_bases');
            });
        }
        Schema::dropIfExists('foodalchemist_fixed_costs');
    }
};
