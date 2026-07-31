<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Einkauf E1 — 1:1-Konfiguration der Rückvergütung je (Team, Lieferant), Schwester zu
 * foodalchemist_supplier_rebate_tiers (000001).
 *
 * Trägt die Steuerung, die NICHT je Stufe gilt:
 *  - `active`               — Rückvergütung für diesen Lieferanten überhaupt anrechnen?
 *  - `selected_tier_id`     — manuell gewählte „angenommene Stufe" (Dropdown im Tool).
 *                             NULL = keine manuelle Wahl → aus Umsatz ableiten.
 *  - `assumed_annual_revenue` — angenommener Jahresumsatz €; höchste nicht überschrittene
 *                             Schwelle wird automatisch gewählt (Auto-Stufe). Später (Phase 2)
 *                             ersetzbar durch echten Spend aus dem Einkaufsjournal.
 *  - `excluded_commodity_groups` — JSON-Liste Warengruppen-Codes ohne Rückvergütung
 *                             (Lieferant gewährt dort keinen Bonus → Originalpreis bleibt).
 *
 * Spiegelt getEffectivePercent/getScenarioTierPercent des Vergleichs-Tools.
 * team-scoped Overlay, KEIN customer_id (Kunden-Dimension = eigene Session).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_supplier_rebate_configs')) {
            return;
        }
        Schema::create('foodalchemist_supplier_rebate_configs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->foreignId('supplier_id')->constrained('foodalchemist_suppliers')->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->foreignId('selected_tier_id')->nullable()
                ->constrained('foodalchemist_supplier_rebate_tiers')->nullOnDelete();
            $table->decimal('assumed_annual_revenue', 14, 2)->nullable();
            $table->json('excluded_commodity_groups')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'supplier_id'], 'fa_supp_rebate_cfg_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_supplier_rebate_configs');
    }
};
