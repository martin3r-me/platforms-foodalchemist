<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 20 · P4 — Einkaufs-Verdrahtung härten (Stale-Marker).
 *
 * Bei „An Bestellung übergeben" halten wir fest, WANN übergeben wurde
 * (`last_handover_at`) und mit WELCHEM Ziel-Stand (`handover_targets_hash` =
 * Hash der `targets`-JSON zum Übergabe-Zeitpunkt). Ändern sich die Ziele danach,
 * weicht der aktuelle Hash ab → das DetailPanel zeigt „Bestellung veraltet —
 * erneut übergeben". Rein additiv, keine Datenänderung, reversibel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_production_orders', function (Blueprint $table) {
            $table->timestamp('last_handover_at')->nullable()->after('cancelled_at')
                ->comment('Spec 20 P4: Zeitpunkt der letzten Bedarfs-Übergabe an den Einkauf');
            $table->string('handover_targets_hash', 40)->nullable()->after('last_handover_at')
                ->comment('Spec 20 P4: Ziel-Hash zum Übergabe-Zeitpunkt (Stale-Erkennung)');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_production_orders', function (Blueprint $table) {
            $table->dropColumn(['last_handover_at', 'handover_targets_hash']);
        });
    }
};
