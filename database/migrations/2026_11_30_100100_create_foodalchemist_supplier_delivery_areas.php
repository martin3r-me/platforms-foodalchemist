<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Liefergebiete eines Lieferanten (Link-Stufe 2d): Liefertage, -zeiten, Bestellschluss und Mindestbestellwert je Gebiet. team_id-gebunden. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_supplier_delivery_areas')) {
            return;
        }
        Schema::create('foodalchemist_supplier_delivery_areas', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->foreignId('supplier_id')->constrained('foodalchemist_suppliers')->cascadeOnDelete();
            $table->string('name');
            $table->text('postal_codes')->nullable()->comment('PLZ-Bereiche, Klartext, z. B. "40-42, 50667"');
            $table->string('delivery_days', 32)->nullable()->comment('ISO-Wochentage CSV, 1=Mo … 7=So');
            $table->string('delivery_von', 8)->nullable()->comment('HH:MM');
            $table->string('delivery_bis', 8)->nullable()->comment('HH:MM');
            $table->string('order_cutoff_time', 8)->nullable()->comment('Bestellschluss HH:MM');
            $table->unsignedInteger('lead_days')->nullable();
            $table->decimal('min_order_value', 12, 2)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['supplier_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_supplier_delivery_areas');
    }
};
