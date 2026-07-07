<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M-K1 / Doc 16: Kalkulations-Block-Schema statt einem opaken Prozent (M12).
 * HK2 = Wareneinsatz + Σ benannte Kostenblöcke (Lohn/Verpackung/Schwund/Lager/
 * Gemeinkosten); VK-Vorschlag = HK2 × (1 + Marge). Additiv — der bestehende
 * hk2_surcharge_pct bleibt als Default-Wert des Gemeinkosten-Blocks (rückwärtskompatibel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'calculation_schema')) {
                $table->json('calculation_schema')->nullable();   // [{key,label,typ,wert,aktiv,sort}]
            }
            if (! Schema::hasColumn('foodalchemist_team_settings', 'stundensatz_eur')) {
                $table->decimal('stundensatz_eur', 10, 2)->nullable();   // Default-Lohnsatz für den arbeitszeit-Block
            }
            if (! Schema::hasColumn('foodalchemist_team_settings', 'margin_pct')) {
                $table->decimal('margin_pct', 7, 2)->nullable();          // Marge auf HK → VK-Vorschlag
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            foreach (['calculation_schema', 'stundensatz_eur', 'margin_pct'] as $col) {
                if (Schema::hasColumn('foodalchemist_team_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
