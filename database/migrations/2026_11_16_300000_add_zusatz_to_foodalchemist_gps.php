<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GP-Regelwerk v3.7.0 §6.6 (Dominique 25.10.2026): Fertig angemachte Salate / Komposit-Convenience mit Fantasienamen. Der Fantasiename steht als
 * Klammer-Zusatz hinten (`Reissalat: schwarzer Reis, Avocado, Azukibohnen, TK / (Mambonito)`) und kommt EXAKT aus Bezeichnung oder Vermarktungsname
 * des Artikels. Dafür das Feld `zusatz` am GP (Naming-Builder) und an der LA-Klassifikation (ClassifyLaJob); Bestand bleibt NULL.
 */
return new class extends Migration
{
    private const TABELLEN = ['foodalchemist_gps', 'foodalchemist_supplier_item_structures'];

    public function up(): void
    {
        foreach (self::TABELLEN as $tabelle) {
            if (Schema::hasTable($tabelle) && ! Schema::hasColumn($tabelle, 'zusatz')) {
                Schema::table($tabelle, function (Blueprint $table) use ($tabelle) {
                    $col = $table->string('zusatz', 120)->nullable()->comment('Klammer-Zusatz (§6.6): Fantasiename exakt aus dem Artikel, z. B. Mambonito → „… / (Mambonito)“');
                    if (Schema::hasColumn($tabelle, 'flavor')) {
                        $col->after('flavor');
                    }
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABELLEN as $tabelle) {
            if (Schema::hasTable($tabelle) && Schema::hasColumn($tabelle, 'zusatz')) {
                Schema::table($tabelle, fn (Blueprint $table) => $table->dropColumn('zusatz'));
            }
        }
    }
};
