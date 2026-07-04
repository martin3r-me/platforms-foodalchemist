<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warengruppen-Lookup (§3 GP-Regelwerk, 15 PKL + Getränke).
 *
 * Globale Stammdaten: team_id NULL = global (D1-Pattern, vgl. Core document_templates).
 * Quelle: wawi_1494.sqlite lookup_warengruppe (code, name) — Import via foodalchemist:import-slice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_lookup_commodity_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('NULL = global (D1)');
            $table->string('code', 8);
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_lookup_commodity_groups');
    }
};
