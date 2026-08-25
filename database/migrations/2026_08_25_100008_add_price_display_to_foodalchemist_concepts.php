<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Concept-Preisdarstellung (Dominique 2026-08-25): `gesamt` (ein Summenpreis fürs
 * Concept — heutiges Verhalten) vs. `einzel` (jedes direkte Kind zeigt seinen Preis,
 * kein Concept-Summenpreis). Reine Concept-Eigenschaft — Foodbook/Format/Speisekarte
 * GEBEN nur durch (Dominique: „das Concept gibt es dann nur weiter").
 *
 * NICHT `price_mode` überladen — das steuert bereits `auto|manuell` (Summe vs. manueller
 * VK) und ist eine andere Achse. Default `gesamt` = non-breaking (Bestand bleibt wie heute;
 * Auswahl-Concepte wie Kuchen/Fingerfood werden manuell auf `einzel` gestellt).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_concepts')) {
            return;
        }

        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_concepts', 'price_display')) {
                $table->string('price_display', 8)->default('gesamt')->after('price_mode'); // gesamt|einzel (Model-Const)
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_concepts')) {
            return;
        }

        Schema::table('foodalchemist_concepts', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_concepts', 'price_display')) {
                $table->dropColumn('price_display');
            }
        });
    }
};
