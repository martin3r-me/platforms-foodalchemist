<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 17 / S1 — Bestell-Logistik am Lieferanten (N-Track).
 * Ergänzt die R9-Konditionen (min_order_value/free_shipping_threshold/email_order
 * liegen bereits) um die fehlende Liefer-Logistik: an welchen Tagen wird geliefert,
 * bis wann muss bestellt sein, wie lang die Vorlaufzeit. Grundlage für die
 * S2-Bestellschienen-Ampel (Liefertag/Bestellschluss).
 */
return new class extends Migration
{
    private array $cols = ['delivery_days', 'order_cutoff_time', 'order_lead_days'];

    public function up(): void
    {
        Schema::table('foodalchemist_suppliers', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_suppliers', 'delivery_days')) {
                $table->string('delivery_days', 32)->nullable()->comment('Liefertage als CSV ISO-Wochentag (1=Mo … 7=So), z. B. "1,3,5"');
            }
            if (! Schema::hasColumn('foodalchemist_suppliers', 'order_cutoff_time')) {
                $table->string('order_cutoff_time', 8)->nullable()->comment('Bestellschluss-Uhrzeit HH:MM');
            }
            if (! Schema::hasColumn('foodalchemist_suppliers', 'order_lead_days')) {
                $table->unsignedInteger('order_lead_days')->nullable()->comment('Vorlaufzeit in Tagen bis Lieferung');
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_suppliers', function (Blueprint $table) {
            foreach ($this->cols as $col) {
                if (Schema::hasColumn('foodalchemist_suppliers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
