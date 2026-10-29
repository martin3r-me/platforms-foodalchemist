<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stammdaten-/Preislisten-Import, Stufe 1a2 (Kurator 29.10.2026): eigene Lieferanten-Dateien ohne Vorlage.
 *
 *  - Zuordnung JE LIEFERANT und Art (preisliste | stammdaten): Überschrift (normalisiert) → Zielfeld, plus Kopf-Signatur und der letzte Kopf,
 *    damit beim nächsten Upload eine geänderte Kopfzeile auffällt statt still weiterzulaufen.
 *  - Ein Lauf im Status „zuordnung_noetig“ behält seine Datei (privater Disk, Zufallsname, nie ausgeliefert), bis zugeordnet oder verworfen wird.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foodalchemist_import_zuordnungen', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('art', 16)->comment('preisliste | stammdaten');
            $table->json('zuordnung')->comment('normalisierte Überschrift → Zielfeld');
            $table->string('kopf_signatur', 64);
            $table->json('kopf')->nullable()->comment('Kopfzeile des letzten Uploads (Originaltext)');
            $table->timestamp('zuletzt_benutzt_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['team_id', 'supplier_id', 'art'], 'fa_import_zuordnung_uq');
        });

        Schema::table('foodalchemist_price_import_runs', function (Blueprint $table) {
            $table->string('datei_pfad', 255)->nullable()->after('link_id')->comment('nur Status zuordnung_noetig: privater Disk-Pfad der Datei');
            $table->string('datei_endung', 8)->nullable()->after('datei_pfad');
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_price_import_runs', function (Blueprint $table) {
            $table->dropColumn(['datei_pfad', 'datei_endung']);
        });
        Schema::dropIfExists('foodalchemist_import_zuordnungen');
    }
};
