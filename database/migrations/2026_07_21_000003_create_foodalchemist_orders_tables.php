<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 17 / S2 — Persistente Bestellschiene (N-Track, OHNE Bestand).
 *
 * `foodalchemist_orders` = die Schiene je Lieferant: höchstens EIN offener `draft`
 * je (team, supplier) sammelt den Bedarf; „Absenden" friert ihn ein (E1). Der
 * Ein-offener-draft-Guard läuft im Service (Transaktion + Lock) statt als partieller
 * Unique-Index — wegen SQLite-Test-Portabilität (Präzedenz R0.2).
 *
 * `foodalchemist_order_lines` = die Bestellzeile PRO ARTIKEL (nicht pro Quelle).
 * `source_contributions` (JSON {source_ref: base_g}) trägt die Quell-Beiträge:
 * `needed_base_g` = Summe, `qty_packs` = ceil(Summe ÷ Gebinde) — Aufrundung IMMER
 * auf dem Aggregat (E3), Re-Import einer Quelle überschreibt nur ihren Schlüssel (E10).
 * Snapshot-Spalten (article_number … pack_price) frieren beim `send` den Beleg ein (E2);
 * im `draft` werden sie aus dem aktiven Preis aufgefrischt (E11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index()->comment('Besitzer-Team (D1)');
            $table->foreignId('supplier_id')->constrained('foodalchemist_suppliers')->cascadeOnDelete();
            $table->string('status', 16)->default('draft')->index()->comment('OrderStatus: draft|sent|confirmed|delivered|cancelled');
            $table->string('reference')->nullable()->comment('Anlass/Event-Bezeichnung (frei)');
            $table->date('desired_delivery_date')->nullable();
            $table->text('note')->nullable();
            $table->decimal('total_net', 12, 2)->default(0)->comment('Cache: Summe order_lines.line_total (netto)');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('delivered_at')->nullable()->comment('Manueller Haken — KEINE Bestandsbuchung (ohne Bestand, E4)');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'supplier_id', 'status']); // Draft-Lookup je Lieferant
        });

        Schema::create('foodalchemist_order_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->foreignId('order_id')->constrained('foodalchemist_orders')->cascadeOnDelete();
            $table->foreignId('supplier_item_id')->nullable()->constrained('foodalchemist_supplier_items')->nullOnDelete();
            $table->foreignId('gp_id')->nullable()->constrained('foodalchemist_gps')->nullOnDelete();

            $table->json('source_contributions')->nullable()->comment('{source_ref: base_g} — Quell-Beiträge (E10)');
            $table->decimal('needed_base_g', 14, 2)->default(0)->comment('Summe der Quell-Beiträge in Gramm');
            $table->decimal('qty_packs', 10, 2)->default(0)->comment('Anzahl Gebinde (ceil auf Aggregat, E3)');
            $table->boolean('is_manual_qty')->default(false)->comment('User-Override — Auto-Recompute lässt qty_packs dann in Ruhe');

            // Snapshot (E2): friert beim send ein; im draft aus aktivem Preis aufgefrischt (E11).
            $table->string('article_number', 64)->nullable();
            $table->string('designation')->nullable();
            $table->string('packaging_unit', 32)->nullable();
            $table->decimal('pack_qty', 10, 3)->nullable()->comment('Gebinde-Inhalt');
            $table->string('unit_code', 16)->nullable();
            $table->decimal('pack_price', 12, 4)->nullable();
            $table->decimal('line_total', 12, 2)->default(0);

            $table->string('note')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['order_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_order_lines');
        Schema::dropIfExists('foodalchemist_orders');
    }
};
