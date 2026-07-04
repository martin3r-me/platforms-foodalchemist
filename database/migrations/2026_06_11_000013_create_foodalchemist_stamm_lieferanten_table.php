<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1-06 / GL-03+V-27: Stamm-Lieferanten-Matrix (Lieferant × Warengruppe).
 *
 * commodity_group_code NULL = globaler Stamm-Lieferant (Quelle stamm_lieferant, 21),
 * gesetzt = Stamm je WG (Quelle stamm_lieferant_wg, 113 — Vault-Skript 212).
 * Gelesen von LeadLaStrategieResolver/LeadLaService (Strategie stamm_lieferant).
 * D1: Kind-Teams erben die Eltern-Matrix lesend (Team-Kette) und ergänzen Eigenes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_preferred_suppliers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->foreignId('supplier_id')->constrained('foodalchemist_suppliers')->cascadeOnDelete();
            $table->string('commodity_group_code', 8)->nullable()->index()->comment('NULL = globaler Stamm');
            $table->timestamps();
            $table->softDeletes();

            // Hinweis: NULL-commodity_group_code ist vom Unique ausgenommen (NULL ≠ NULL auf allen Engines)
            // — Duplikat-Schutz für global macht der Service.
            $table->unique(['team_id', 'supplier_id', 'commodity_group_code'], 'fa_stamm_lief_team_supp_wg_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_preferred_suppliers');
    }
};
