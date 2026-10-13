<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §12 A3 / Schritt 4: Stamm-Paket als echte Sichtbarkeitsregel.
 *
 * `team_id NULL` hieß bisher „für alle sichtbar". Jetzt bekommen globale Zeilen eine Klasse:
 *   basis       = bekommt jedes Team (Verhalten wie bisher)
 *   stamm_paket = nur Teams mit Opt-in (Tabelle foodalchemist_team_stamm_optins)
 *
 * Betrifft GP, Lieferanten, Artikel. Preiszeilen bekommen KEINE eigene Spalte (ob das Paket Preise enthält, ist offen): ihre Klasse
 * ergibt sich aus dem Artikel (Abstimmung mit Stufe 3a, sonst zwei Wahrheiten).
 *
 * Default 'basis' ändert nichts am Bestand: alle Zeilen (auch Team 6 auf demo) bleiben sichtbar wie heute.
 * Teamgebundene Zeilen (team_id gesetzt) sind immer 'basis' — die Klasse gilt nur für globale Zeilen.
 * Keine Datenverschiebung, kein Backfill.
 */
return new class extends Migration
{
    private const TABELLEN = [
        'foodalchemist_gps',
        'foodalchemist_suppliers',
        'foodalchemist_supplier_items',
    ];

    public function up(): void
    {
        foreach (self::TABELLEN as $tabelle) {
            if (Schema::hasColumn($tabelle, 'stamm_class')) {
                continue; // idempotent
            }
            Schema::table($tabelle, function (Blueprint $table) {
                $table->string('stamm_class', 16)->default('basis')
                    ->comment('basis | stamm_paket — gilt nur bei team_id NULL (Spec 82 §12 A3)');
            });
        }

        if (! Schema::hasTable('foodalchemist_team_stamm_optins')) {
            Schema::create('foodalchemist_team_stamm_optins', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('team_id')->unique()->comment('Mandant (Oberteam); Unter-Teams erben über die Kette');
                $table->timestamp('opted_in_at')->nullable();
                $table->unsignedBigInteger('opted_in_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_team_stamm_optins');
        foreach (self::TABELLEN as $tabelle) {
            if (Schema::hasColumn($tabelle, 'stamm_class')) {
                Schema::table($tabelle, fn (Blueprint $table) => $table->dropColumn('stamm_class'));
            }
        }
    }
};
