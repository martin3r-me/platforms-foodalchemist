<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GP-Regelwerk v3.7.0 §6.6 (Dominique 24.10.2026): Geschmackszutat. Ist ein Produkt eine gewürzte/aromatisierte Variante einer Grundware, bleibt die
 * Grundware Hauptzutat und die Geschmackszutat steht als ERSTE Eigenschaft nach dem Doppelpunkt (`Käse: Chili, frisch, Scheiben, 45 % Fett i. Tr.`).
 * Dafür ein eigenes Feld `flavor` am GP (Naming-Builder) und an der LA-Klassifikation (ClassifyLaJob); Bestand bleibt NULL.
 */
return new class extends Migration
{
    private const TABELLEN = ['foodalchemist_gps' => 'form', 'foodalchemist_supplier_item_structures' => 'form'];

    public function up(): void
    {
        foreach (self::TABELLEN as $tabelle => $nach) {
            if (Schema::hasTable($tabelle) && ! Schema::hasColumn($tabelle, 'flavor')) {
                Schema::table($tabelle, function (Blueprint $table) use ($tabelle, $nach) {
                    $col = $table->string('flavor', 120)->nullable()->comment('Geschmackszutat (§6.6): erste Eigenschaft im Namen, z. B. Chili, Kräuter, Knoblauch');
                    if (Schema::hasColumn($tabelle, $nach)) {
                        $col->after($nach);
                    }
                });
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABELLEN) as $tabelle) {
            if (Schema::hasTable($tabelle) && Schema::hasColumn($tabelle, 'flavor')) {
                Schema::table($tabelle, fn (Blueprint $table) => $table->dropColumn('flavor'));
            }
        }
    }
};
