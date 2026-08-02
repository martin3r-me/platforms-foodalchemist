<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 33 · P1 — Gültigkeitsfenster am Foodbook.
 *
 * Die Speisekarte hat `gueltig_von`/`gueltig_bis` seit ihrer Anlage, der Speiseplan trägt seine
 * Zeitachse in den Einträgen (`entry_date`). Das Foodbook kannte bisher nur `jahr` — zu grob,
 * um „läuft heute" zu beantworten, und vor allem ohne Ende: ohne `gueltig_bis` bliebe ein
 * einmal aktiv gesetztes Foodbook für immer im Portfolio.
 *
 * `jahr` bleibt als grobe Einordnung und Sortierschlüssel bestehen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_foodbooks')
            || Schema::hasColumn('foodalchemist_foodbooks', 'gueltig_von')) {
            return;
        }

        Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
            $table->date('gueltig_von')->nullable()->after('jahr')
                ->comment('Spec 33: ab wann läuft dieses Foodbook (leer = unbefristet)');
            $table->date('gueltig_bis')->nullable()->after('gueltig_von')
                ->comment('Spec 33: bis wann (leer = unbefristet; abgelaufen ⇒ läuft nicht mehr)');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_foodbooks', 'gueltig_von')) {
            Schema::table('foodalchemist_foodbooks', function (Blueprint $table) {
                $table->dropColumn(['gueltig_von', 'gueltig_bis']);
            });
        }
    }
};
