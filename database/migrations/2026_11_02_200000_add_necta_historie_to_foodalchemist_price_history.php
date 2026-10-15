<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Necta PR 2b, Stufe 2 (16.10.2026): Preis-Vergangenheit aus `supplier-item-price-historys` in `foodalchemist_price_history`
 * (quelle = 'necta_historie'), abgestimmt mit Claudia (PreisVerlaufService, Preis-Linie):
 *  - `price_id` nullable: alte Stände haben oft keine FA-Preiszeile mehr.
 *  - `necta_historie_id` unique: Idempotenz über die Necta-History-ID.
 *  - `details` (JSON): Herkunftsinfo (Necta `source`, `amountFrom`, `type`).
 *  - Index (supplier_item_id, status_valid_from) für die Linie.
 * Keine Daten: `foodalchemist:necta-preishistorie-nachholen`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_price_history', function (Blueprint $table) {
            $table->unsignedBigInteger('price_id')->nullable()->change();
        });
        if (! Schema::hasColumn('foodalchemist_price_history', 'necta_historie_id')) {
            Schema::table('foodalchemist_price_history', function (Blueprint $table) {
                $table->unsignedBigInteger('necta_historie_id')->nullable()->unique()->after('legacy_id')->comment('Necta supplier-item-price-historys.id');
                $table->json('details')->nullable()->after('quelle')->comment('necta_historie: source, amount_from, type');
                $table->index(['supplier_item_id', 'status_valid_from'], 'fa_price_history_item_von_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_price_history', 'necta_historie_id')) {
            Schema::table('foodalchemist_price_history', function (Blueprint $table) {
                $table->dropIndex('fa_price_history_item_von_idx');
                $table->dropUnique(['necta_historie_id']);
                $table->dropColumn(['necta_historie_id', 'details']);
            });
        }
    }
};
