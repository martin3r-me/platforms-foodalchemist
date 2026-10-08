<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 75a · Lieferschein als eigener Beleg. Ein Lieferschein gehört einem Lieferanten und kann
 * Positionen aus mehreren Bestellungen tragen (n:m); Positionen ohne Bestellzeile sind erlaubt
 * (Ware ohne Bestellung). Gebuchte Mengen werden als Summe auf die Bestellzeile abgeleitet
 * (`received_qty_packs`), Lager/Kontingent/Journal laufen über den vorhandenen Weg. Rein additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_delivery_notes')) {
            Schema::create('foodalchemist_delivery_notes', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('supplier_id')->index();
                $table->string('delivery_note_number', 64)->nullable();
                $table->date('delivered_on');
                $table->string('status', 16)->default('entwurf')->index();   // entwurf | gebucht | storniert
                $table->unsignedBigInteger('inventory_location_id')->nullable(); // Ziel für Ware ohne Bestellung
                $table->text('note')->nullable();
                $table->unsignedBigInteger('attachment_context_file_id')->nullable();
                $table->string('attachment_name')->nullable();
                $table->dateTime('booked_at')->nullable();
                $table->unsignedBigInteger('booked_by')->nullable();
                $table->dateTime('cancelled_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['team_id', 'supplier_id', 'delivery_note_number'], 'fa_dn_team_supplier_number');
            });
        }
        if (! Schema::hasTable('foodalchemist_delivery_note_lines')) {
            Schema::create('foodalchemist_delivery_note_lines', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('delivery_note_id')->index();
                $table->unsignedBigInteger('order_line_id')->nullable()->index();   // null = ohne Bestellung
                $table->unsignedBigInteger('supplier_item_id')->nullable();
                $table->unsignedBigInteger('gp_id')->nullable();
                $table->string('designation')->nullable();
                $table->decimal('qty_packs', 10, 2)->nullable();          // geliefert in Gebinden (mit Bestellzeile)
                $table->decimal('expected_qty_packs', 10, 2)->nullable(); // offen laut Bestellung beim Erfassen
                $table->decimal('menge', 12, 3)->nullable();              // geliefert in kg/l/Stk (ohne Bestellung)
                $table->decimal('pack_price_snapshot', 12, 4)->nullable();
                // fehlt | zu_wenig | zu_viel | ersatzartikel | beschaedigt | qualitaet | sonstiges
                $table->string('abweichung_grund', 24)->nullable();
                $table->string('note')->nullable();
                $table->unsignedBigInteger('inventory_movement_id')->nullable();   // Zugang ohne Bestellung
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_delivery_note_lines');
        Schema::dropIfExists('foodalchemist_delivery_notes');
    }
};
