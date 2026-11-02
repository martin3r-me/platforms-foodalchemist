<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 94 Stufe 5b · Reihenfolge der Schritte je Konfigurator (Standard: Tageszeit zuerst, Bestand bekommt den Standard)
 * und `payload_json` am Konfigurator-Konzept für die Wording-Überschreibung je Konfigurator — derselbe Mechanismus wie
 * Foodbook-Block, Format-Slot und Angebots-Block (`wording_overrides` je Concept-Slot-Id, WordingResolver). Additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('foodalchemist_konfiguratoren', 'reihenfolge')) {
            Schema::table('foodalchemist_konfiguratoren', function (Blueprint $table) {
                $table->string('reihenfolge', 20)->default('tageszeit_zuerst');
            });
        }
        if (! Schema::hasColumn('foodalchemist_konfigurator_konzepte', 'payload_json')) {
            Schema::table('foodalchemist_konfigurator_konzepte', function (Blueprint $table) {
                $table->json('payload_json')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_konfigurator_konzepte', 'payload_json')) {
            Schema::table('foodalchemist_konfigurator_konzepte', fn (Blueprint $t) => $t->dropColumn('payload_json'));
        }
        if (Schema::hasColumn('foodalchemist_konfiguratoren', 'reihenfolge')) {
            Schema::table('foodalchemist_konfiguratoren', fn (Blueprint $t) => $t->dropColumn('reihenfolge'));
        }
    }
};
