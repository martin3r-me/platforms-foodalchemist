<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 94b · Stufe 7b: Einzelabrechnung je Leistungs-Vorlage und Konfigurator-Leistung — `abrechnung` (all_in | aufwand,
 * Standard all_in) und die Einheit der Abrechnung aus dem Einheiten-Vokabular (leer = Stunde bzw. Stück aus „Preis je“).
 * Satz ist der VK-Satz der Leistung. Additiv; Bestand rechnet unverändert im All-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['foodalchemist_leistung_vorlagen', 'foodalchemist_konfigurator_leistungen'] as $tabelle) {
            if (! Schema::hasColumn($tabelle, 'abrechnung')) {
                Schema::table($tabelle, function (Blueprint $table) {
                    $table->string('abrechnung', 12)->default('all_in');
                    $table->unsignedBigInteger('abrechnung_einheit_vocab_id')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['foodalchemist_leistung_vorlagen', 'foodalchemist_konfigurator_leistungen'] as $tabelle) {
            if (Schema::hasColumn($tabelle, 'abrechnung')) {
                Schema::table($tabelle, fn (Blueprint $t) => $t->dropColumn(['abrechnung', 'abrechnung_einheit_vocab_id']));
            }
        }
    }
};
