<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Einkauf E1 — strukturierte Rückvergütungs-Staffeln (Volumen-Rabatt) je Lieferant.
 *
 * Löst das flache `foodalchemist_suppliers.rebate_pct` ab, das (a) nur EIN Prozentwert
 * ist und (b) am GLOBALEN Lieferanten hängt (team_id NULL, read-only für Teams) — ein
 * Kind-Team kann dort keine eigene Kondition pflegen. Rückvergütung ist aber pro Team
 * verhandelt. Darum team-scopes OVERLAY über dem globalen Lieferanten, exakt das Muster
 * von foodalchemist_gp_la_preferences (V-27).
 *
 * Eine Zeile = eine Staffelstufe (Schwelle ab € → Rabatt %). Die 1:1-Konfiguration
 * (aktiv, gewählte Stufe, angenommener Jahresumsatz, ausgeschlossene Warengruppen) liegt
 * in der Schwester-Tabelle foodalchemist_supplier_rebate_configs (000002).
 *
 * BEWUSST team-scoped, KEIN customer_id — die Kunden-Dimension ist ein eigenes
 * Folge-Projekt (eigene Session); dann kommt eine customer_id-Achse per ALTER dazu.
 * Index-Namen kurz gehalten (<64, Plattform-DB-Kompat SQLite/MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_supplier_rebate_tiers')) {
            return;
        }
        Schema::create('foodalchemist_supplier_rebate_tiers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->foreignId('supplier_id')->constrained('foodalchemist_suppliers')->cascadeOnDelete();
            $table->decimal('threshold_eur', 12, 2)->default(0);   // Schwelle ab Jahresumsatz € (0 = ab dem ersten €)
            $table->decimal('percent', 5, 2);                      // Rabatt % auf dieser Stufe
            $table->unsignedInteger('sort')->default(0);           // Reihenfolge (aufsteigend nach Schwelle gepflegt)
            $table->timestamps();
            $table->softDeletes();

            // Leserichtung: Staffel eines (Team, Lieferant) in Reihenfolge.
            $table->index(['team_id', 'supplier_id', 'sort'], 'fa_supp_rebate_tier_read_idx');
            // Keine zwei Stufen mit identischer Schwelle je (Team, Lieferant).
            $table->unique(['team_id', 'supplier_id', 'threshold_eur'], 'fa_supp_rebate_tier_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_supplier_rebate_tiers');
    }
};
