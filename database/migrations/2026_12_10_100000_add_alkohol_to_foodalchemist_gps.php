<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 94c · Fester Alkohol-Wert am GP (Kurator/Dominique 03.11.2026), alle Warengruppen; ja / nein / NULL (offen). Er hat Vorrang vor
 * den Artikeldaten in der 8a-Kette (GpAggregateService::enthaeltAlkohol → spec_contains_alcohol). `alkohol_source`: abgeleitet
 * (einmalige Vorbelegung WG 15, foodalchemist:alkohol-vorbelegen) oder manuell (Modal, gps.POST/PUT) — manuell überschreibt kein Lauf.
 * Additiv; die Vorbelegung läuft nur auf Ansage.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_gps', 'alkohol')) {
            Schema::table('foodalchemist_gps', function (Blueprint $table) {
                $table->boolean('alkohol')->nullable();
                $table->string('alkohol_source', 16)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_gps', 'alkohol')) {
            Schema::table('foodalchemist_gps', fn (Blueprint $t) => $t->dropColumn(['alkohol', 'alkohol_source']));
        }
    }
};
