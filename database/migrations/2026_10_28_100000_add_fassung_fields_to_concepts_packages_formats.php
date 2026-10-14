<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 Abo für Konzepte/Pakete/Formate, PR 1 (Fassungs-Basis): dieselben Felder wie die Rezept-Fassung
 * (2026_10_22_200000/200100) an foodalchemist_concepts (kind concept UND paket), foodalchemist_packages und
 * foodalchemist_formats. Fassung = eingefrorene, schreibgeschützte Zeile (ist_fassung = 1, fassung_von_id = Arbeitszeile).
 * Additiv, nullable/Default, hasColumn-Guards (idempotent), kein ->after(), keine ALTER-add-FK (SQLite).
 */
return new class extends Migration
{
    private const TABELLEN = [
        'foodalchemist_concepts' => 'concept',
        'foodalchemist_packages' => 'package',
        'foodalchemist_formats' => 'format',
    ];

    private const SPALTEN = ['ist_fassung', 'fassung_von_id', 'fassung_nr', 'inhalt_hash', 'veroeffentlicht_at', 'veroeffentlicht_von', 'aenderungsnotiz', 'fassung_allergen_geaendert'];

    public function up(): void
    {
        foreach (self::TABELLEN as $tabelle => $kurz) {
            Schema::table($tabelle, function (Blueprint $table) use ($tabelle, $kurz) {
                if (! Schema::hasColumn($tabelle, 'ist_fassung')) {
                    $table->boolean('ist_fassung')->default(false)->index("fa_{$kurz}_ist_fassung");
                }
                if (! Schema::hasColumn($tabelle, 'fassung_von_id')) {
                    $table->unsignedBigInteger('fassung_von_id')->nullable()->index("fa_{$kurz}_fassung_von");
                }
                if (! Schema::hasColumn($tabelle, 'fassung_nr')) {
                    $table->unsignedInteger('fassung_nr')->nullable();
                }
                if (! Schema::hasColumn($tabelle, 'inhalt_hash')) {
                    $table->string('inhalt_hash', 64)->nullable();
                }
                if (! Schema::hasColumn($tabelle, 'veroeffentlicht_at')) {
                    $table->timestamp('veroeffentlicht_at')->nullable();
                }
                if (! Schema::hasColumn($tabelle, 'veroeffentlicht_von')) {
                    $table->unsignedBigInteger('veroeffentlicht_von')->nullable();
                }
                if (! Schema::hasColumn($tabelle, 'aenderungsnotiz')) {
                    $table->text('aenderungsnotiz')->nullable();
                }
                if (! Schema::hasColumn($tabelle, 'fassung_allergen_geaendert')) {
                    $table->boolean('fassung_allergen_geaendert')->default(false);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABELLEN as $tabelle => $kurz) {
            Schema::table($tabelle, function (Blueprint $table) use ($tabelle, $kurz) {
                foreach (['ist_fassung' => "fa_{$kurz}_ist_fassung", 'fassung_von_id' => "fa_{$kurz}_fassung_von"] as $spalte => $index) {
                    if (Schema::hasColumn($tabelle, $spalte)) {
                        $table->dropIndex($index);
                    }
                }
                foreach (self::SPALTEN as $spalte) {
                    if (Schema::hasColumn($tabelle, $spalte)) {
                        $table->dropColumn($spalte);
                    }
                }
            });
        }
    }
};
