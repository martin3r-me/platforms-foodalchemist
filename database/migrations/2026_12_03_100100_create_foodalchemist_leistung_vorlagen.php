<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 94 · Leistungs-Vorlagen: Personal, Equipment, Logistik und Sonstiges einmal mit EK und VK pflegen und in
 * Konfiguratoren übernehmen (Kopie). Vererbung über die Team-Kette (BelongsToTeamHierarchy). Rein additiv.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_leistung_vorlagen')) {
            return;
        }
        Schema::create('foodalchemist_leistung_vorlagen', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('kategorie', 16)->index();                       // personal | equipment | logistik | sonstiges
            $table->string('bezeichnung');
            $table->text('notiz')->nullable();
            $table->string('mengen_regel', 16)->default('fest');            // fest | schluessel | stufen
            $table->decimal('anzahl', 10, 2)->nullable();
            $table->string('bezug', 16)->nullable();                        // gaeste | leistung
            $table->unsignedBigInteger('bezug_leistung_id')->nullable()->index();
            $table->decimal('schluessel_n', 10, 2)->nullable();
            $table->decimal('min', 10, 2)->nullable();
            $table->decimal('max', 10, 2)->nullable();
            $table->unsignedInteger('ab_pax')->nullable();
            $table->unsignedInteger('bis_pax')->nullable();
            $table->json('stufen_json')->nullable();
            $table->decimal('reserve_pct', 5, 2)->nullable();
            $table->string('preis_je', 16)->default('stueck');              // stueck | stunde
            $table->string('stunden_quelle', 16)->nullable();               // einsatzzeit | fest
            $table->decimal('stunden', 6, 2)->nullable();
            $table->decimal('stunden_zuschlag', 5, 2)->nullable();
            $table->string('haeufigkeit', 16)->default('je_event');         // je_event | je_tageszeit
            $table->decimal('ek_satz', 10, 2)->default(0);
            $table->unsignedBigInteger('markup_class_id')->nullable()->index();
            $table->decimal('vk_satz', 10, 2)->nullable();
            $table->string('vk_modus', 8)->default('auto');                 // auto | fixed
            $table->string('vk_begruendung')->nullable();
            $table->boolean('aktiv')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_leistung_vorlagen');
    }
};
