<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §13 D (Variante B): FA-eigene Plan-Preis-Tabelle ohne team_id (gilt für die ganze Plattform).
 * Append-only: Ein neuer Preis ist eine neue Zeile mit „gültig ab“; die Historie bleibt. Nur die gültig_bis der
 * Vorgängerzeile wird beim Anlegen gesetzt. Gepflegt nur durch den Plattform-Admin (PlanPreisService), jede Änderung
 * geht ins Plattform-Protokoll. Bausteine: basis | user | unterteam | stamm_paket | master_abo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_plan_preise', function (Blueprint $table) {
            $table->id();
            $table->string('baustein', 32);
            $table->decimal('betrag_monat', 10, 2);
            $table->date('gueltig_ab');
            $table->date('gueltig_bis')->nullable();
            $table->text('aenderungsnotiz')->nullable();
            $table->unsignedBigInteger('geaendert_von')->nullable();
            $table->timestamps();

            $table->index(['baustein', 'gueltig_ab']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_plan_preise');
    }
};
