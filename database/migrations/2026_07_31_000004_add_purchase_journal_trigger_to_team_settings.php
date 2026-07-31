<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Einkauf E2 — konfigurierbarer Auslöser, ab welchem Bestellschienen-Status ein
 * in FA getätigter Einkauf als Ist-Einkauf ins Journal (foodalchemist_purchase_transactions,
 * source=fa_order) gebucht wird: 'sent' (schon beim Absenden) oder 'delivered'
 * (erst bei Lieferung; Default = sauberste Spend-Wahrheit). Rückwärtskompatibel.
 * Engine-agnostisch (kein ->after / FK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'purchase_journal_trigger')) {
                $table->string('purchase_journal_trigger', 16)->default('delivered');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            $table->dropColumn('purchase_journal_trigger');
        });
    }
};
