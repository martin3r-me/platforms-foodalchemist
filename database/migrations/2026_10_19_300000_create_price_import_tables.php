<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §3 / Schritt 4d: Preislisten-Import für Mandanten (CSV zuerst), Stufe „Abgleich + Prüfliste".
 *
 * Ein Lauf = eine hochgeladene Preisliste eines Mandanten. Jede Zeile wird gegen die SICHTBAREN Artikel
 * abgeglichen (Artikelnummer + Lieferant, danach EAN) und mit ihrem Ergebnis abgelegt. Geschrieben wird hier
 * KEIN Preis: Treffer warten auf die Schreib-Schicht (Teampreis über PriceService), Zeilen ohne Treffer sind die
 * Prüfliste. Es werden keine Artikel angelegt. Append-only: ein neuer Upload ist ein neuer Lauf.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_price_import_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('supplier_id')->nullable()->comment('Standard-Lieferant der Datei (wenn keine Lieferant-Spalte)');
            $table->string('filename', 255);
            $table->string('status', 16)->default('staged')->comment('staged | applied');
            $table->json('mapping')->nullable();
            $table->json('counts')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('foodalchemist_price_import_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->foreignId('run_id')->constrained('foodalchemist_price_import_runs')->cascadeOnDelete();
            $table->unsignedInteger('row_nr');
            $table->string('supplier_name', 255)->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('article_number', 64)->nullable();
            $table->string('ean', 32)->nullable();
            $table->string('designation', 255)->nullable();
            $table->string('unit', 64)->nullable();
            $table->decimal('price', 12, 4)->nullable();
            $table->date('valid_from')->nullable();
            $table->string('status', 24)->comment('treffer | ohne_treffer | mehrdeutig | lieferant_unbekannt | fehler | doppelt');
            $table->string('match_method', 24)->nullable()->comment('artikelnummer | ean');
            $table->unsignedBigInteger('supplier_item_id')->nullable()->index();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['run_id', 'status'], 'fa_pimp_lines_run_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_price_import_lines');
        Schema::dropIfExists('foodalchemist_price_import_runs');
    }
};
