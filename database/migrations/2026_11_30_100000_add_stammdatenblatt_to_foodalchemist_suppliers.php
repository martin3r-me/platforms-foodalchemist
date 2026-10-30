<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lieferanten-Stammdatenblatt (Link-Stufe 2d): Firmendaten, Bestellwege und Konditionen-Texte, die der Lieferant selbst pflegt. Rein additiv; Mindestbestellwert,
 * Frei-Haus-Grenze, Zahlungsziel, Liefertage, Bestellschluss und Vorlaufzeit gibt es schon (R9.1 / Bestellschiene) und werden mitbenutzt.
 */
return new class extends Migration
{
    private array $cols = ['legal_name', 'vat_id', 'phone', 'order_channels', 'freight_note', 'small_order_surcharge', 'payment_terms_note', 'complaint_path'];

    public function up(): void
    {
        Schema::table('foodalchemist_suppliers', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_suppliers', 'legal_name')) {
                $table->string('legal_name')->nullable()->comment('Firmierung laut Handelsregister');
            }
            if (! Schema::hasColumn('foodalchemist_suppliers', 'vat_id')) {
                $table->string('vat_id', 32)->nullable()->comment('USt-IdNr.');
            }
            if (! Schema::hasColumn('foodalchemist_suppliers', 'phone')) {
                $table->string('phone', 64)->nullable()->comment('Zentrale');
            }
            if (! Schema::hasColumn('foodalchemist_suppliers', 'order_channels')) {
                $table->json('order_channels')->nullable()->comment('Bestellwege: email|telefon|fax|portal|edi');
            }
            if (! Schema::hasColumn('foodalchemist_suppliers', 'freight_note')) {
                $table->text('freight_note')->nullable()->comment('Fracht/Mindermengen-Regel im Klartext');
            }
            if (! Schema::hasColumn('foodalchemist_suppliers', 'small_order_surcharge')) {
                $table->decimal('small_order_surcharge', 12, 2)->nullable()->comment('Mindermengenzuschlag netto');
            }
            if (! Schema::hasColumn('foodalchemist_suppliers', 'payment_terms_note')) {
                $table->string('payment_terms_note')->nullable()->comment('Zahlungsbedingungen im Klartext (Skonto usw.)');
            }
            if (! Schema::hasColumn('foodalchemist_suppliers', 'complaint_path')) {
                $table->text('complaint_path')->nullable()->comment('Reklamationsweg');
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
