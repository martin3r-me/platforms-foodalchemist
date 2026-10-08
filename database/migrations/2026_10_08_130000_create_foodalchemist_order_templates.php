<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 67 B · Bestellvorlagen („Musterbestellungen"): gespeicherte Bestellrunde — Grundprodukte mit
 * Menge (oder fester Artikel in Gebinden); Anwenden läuft über die Bestellrunde. Rein additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_order_templates')) {
            Schema::create('foodalchemist_order_templates', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('team_id')->index();
                $table->string('name', 120);
                $table->text('note')->nullable();
                $table->unsignedTinyInteger('weekday')->nullable();   // 1 = Montag … 7 = Sonntag
                $table->dateTime('last_used_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
        if (! Schema::hasTable('foodalchemist_order_template_lines')) {
            Schema::create('foodalchemist_order_template_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('team_id')->index();
                $table->unsignedBigInteger('order_template_id')->index();
                // Basis ist das Grundprodukt (Artikel wählt beim Anwenden die Lead-Strategie);
                // fester Artikel nur für Ware ohne GP oder markengebundene Ware.
                $table->string('type', 16)->default('gp');                 // gp | recipe | supplier_item
                $table->unsignedBigInteger('gp_id')->nullable()->index();
                $table->unsignedBigInteger('recipe_id')->nullable()->index();
                $table->unsignedBigInteger('supplier_item_id')->nullable()->index();
                $table->decimal('qty', 12, 3)->default(1);
                // gp: kg | g | stk · Rezept: portions | ansaetze | kg · Artikel: gebinde
                $table->string('unit', 16)->default('kg');
                $table->string('note', 255)->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->uuid('uuid')->nullable()->unique();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_order_template_lines');
        Schema::dropIfExists('foodalchemist_order_templates');
    }
};
