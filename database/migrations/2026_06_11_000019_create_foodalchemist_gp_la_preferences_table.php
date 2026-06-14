<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M3-06 / V-27: team-scoped Overlay über der globalen Lead-LA-Kette (GL-03).
 * EIN globaler Default-Lead (gps.lead_la_supplier_item_id), Team-Abweichungen
 * hier: gesperrt (LA fällt aus der effektiven Kette) + gepinnt (fixiert als
 * effektiver Lead, überlebt Bulk-Neuwahl — schließt GL-03 A-4 bevorzugt_lock).
 * Index-Namen auto-generiert (07 §7 Plattform-DB-Kompat).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_gp_la_preferences', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->foreignId('gp_id')->constrained('foodalchemist_gps')->cascadeOnDelete();
            $table->foreignId('supplier_item_id')->constrained('foodalchemist_supplier_items')->cascadeOnDelete();
            $table->boolean('gesperrt')->default(false);
            $table->boolean('gepinnt')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'gp_id', 'supplier_item_id'], 'fa_gp_la_pref_team_gp_si_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_gp_la_preferences');
    }
};
