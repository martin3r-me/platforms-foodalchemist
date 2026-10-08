<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 66 · Inventur (Stufe 1): Stichtag + Lagerort + Zählliste. Soll-Bestand und Bewertungspreis
 * werden beim Anlegen eingefroren; Buchen setzt den Bestand auf die gezählte Menge.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_inventory_counts')) {
            Schema::create('foodalchemist_inventory_counts', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('inventory_location_id')->nullable()->index();
                $table->date('count_date')->index();
                $table->string('status', 16)->default('offen')->index();   // offen | gebucht
                $table->text('note')->nullable();
                $table->decimal('value_total', 14, 2)->nullable();          // Σ gezählte Menge × Preis, beim Buchen
                $table->dateTime('booked_at')->nullable();
                $table->unsignedBigInteger('booked_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['team_id', 'status', 'count_date'], 'fa_inv_counts_team_status_date_idx');
            });
        }
        if (! Schema::hasTable('foodalchemist_inventory_count_lines')) {
            Schema::create('foodalchemist_inventory_count_lines', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('inventory_count_id')->index();
                $table->unsignedBigInteger('gp_id')->nullable()->index();
                $table->unsignedBigInteger('supplier_item_id')->nullable()->index();
                $table->string('base_unit', 16)->default('g');
                $table->decimal('qty_expected', 16, 4)->default(0);          // Soll-Bestand beim Anlegen
                $table->decimal('qty_counted', 16, 4)->nullable();           // null = nicht gezählt
                $table->decimal('price_per_base', 14, 6)->nullable();        // € je g/ml/Stk, beim Anlegen
                $table->unsignedInteger('position')->default(0);
                $table->string('note', 255)->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_inventory_count_lines');
        Schema::dropIfExists('foodalchemist_inventory_counts');
    }
};
