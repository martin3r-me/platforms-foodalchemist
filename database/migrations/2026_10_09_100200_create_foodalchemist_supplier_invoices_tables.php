<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 75b · Lieferanten-Rechnung als eigener Beleg (Triple Match Bestellung ⇄ Lieferschein ⇄ Rechnung).
 * Eine Rechnung gehört einem Lieferanten und kann mehrere Lieferscheine/Bestellungen abrechnen
 * (Sammelrechnung). Positionen zeigen auf Lieferschein- und/oder Bestellzeile; Nebenkosten (Fracht,
 * Pfand, Zuschlag, Rabatt) ohne Artikel. Dazu Team-Einstellungen: Toleranzen des Abgleichs. Rein additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_supplier_invoices')) {
            Schema::create('foodalchemist_supplier_invoices', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index('fa_si_team');
                $table->unsignedBigInteger('supplier_id')->index('fa_si_supplier');
                $table->string('invoice_number', 64)->nullable();
                $table->date('invoice_date');
                $table->date('due_date')->nullable();
                $table->decimal('total_net', 12, 2)->nullable();          // laut Beleg (Erfassungskontrolle)
                $table->string('status', 16)->default('erfasst');          // erfasst | freigegeben | bezahlt | storniert
                $table->boolean('is_disputed')->default(false);            // strittig
                $table->date('paid_at')->nullable();
                $table->text('note')->nullable();
                $table->unsignedBigInteger('attachment_context_file_id')->nullable();
                $table->string('attachment_name', 255)->nullable();
                $table->dateTime('approved_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->dateTime('cancelled_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['team_id', 'supplier_id', 'invoice_number'], 'fa_si_team_sup_nr');
                $table->index(['team_id', 'status'], 'fa_si_team_status');
            });
        }
        if (! Schema::hasTable('foodalchemist_supplier_invoice_lines')) {
            Schema::create('foodalchemist_supplier_invoice_lines', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index('fa_sil_team');
                $table->unsignedBigInteger('invoice_id')->index('fa_sil_invoice');
                $table->unsignedBigInteger('delivery_note_line_id')->nullable()->index('fa_sil_dnl');
                $table->unsignedBigInteger('order_line_id')->nullable()->index('fa_sil_ol');
                $table->unsignedBigInteger('supplier_item_id')->nullable();
                $table->string('art', 16)->default('ware');               // ware | fracht | pfand | zuschlag | rabatt
                $table->string('designation', 255)->nullable();
                $table->decimal('qty_packs', 10, 2)->nullable();
                $table->decimal('pack_price', 12, 4)->nullable();
                $table->decimal('line_net', 12, 2)->nullable();
                $table->string('begruendung', 255)->nullable();           // akzeptierte Abweichung
                $table->string('note', 255)->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_team_settings', 'tm_toleranz_preis_pct')) {
                $table->decimal('tm_toleranz_preis_pct', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('foodalchemist_team_settings', 'tm_toleranz_preis_eur')) {
                $table->decimal('tm_toleranz_preis_eur', 8, 4)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            foreach (['tm_toleranz_preis_pct', 'tm_toleranz_preis_eur'] as $spalte) {
                if (Schema::hasColumn('foodalchemist_team_settings', $spalte)) {
                    $table->dropColumn($spalte);
                }
            }
        });
        Schema::dropIfExists('foodalchemist_supplier_invoice_lines');
        Schema::dropIfExists('foodalchemist_supplier_invoices');
    }
};
