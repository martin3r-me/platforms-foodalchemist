<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 73 · Bestellrunde: Quellen + Einstellungen speichern (Runde öffnet vollständig wieder),
 * Source-Refs (sauberes Löschen) und Lager-Reservierung (kein doppeltes Anrechnen über Runden).
 * Rein additiv, nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_order_rounds', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_order_rounds', 'sources')) {
                $table->json('sources')->nullable();
                $table->json('overrides')->nullable();
                $table->json('source_refs')->nullable();
                $table->json('lager_reserviert')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_order_rounds', function (Blueprint $table) {
            $table->dropColumn(['sources', 'overrides', 'source_refs', 'lager_reserviert']);
        });
    }
};
