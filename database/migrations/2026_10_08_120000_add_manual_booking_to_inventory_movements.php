<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 67 A · Lagerbewegungen von Hand: Grund, Bewertung, Buchender, Umlagerungs-Paar, Storno-Bezug.
 * Rein additiv, alle Spalten nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_inventory_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_inventory_movements', 'reason')) {
                $table->string('reason', 32)->nullable()->index();
                $table->decimal('price_per_base', 14, 6)->nullable();
                $table->decimal('value_eur', 14, 2)->nullable();
                $table->unsignedBigInteger('booked_by')->nullable();
                $table->string('transfer_ref', 40)->nullable()->index();
                $table->unsignedBigInteger('storno_of_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_inventory_movements', function (Blueprint $table) {
            $table->dropIndex(['reason']);
            $table->dropIndex(['transfer_ref']);
            $table->dropIndex(['storno_of_id']);
            $table->dropColumn(['reason', 'price_per_base', 'value_eur', 'booked_by', 'transfer_ref', 'storno_of_id']);
        });
    }
};
