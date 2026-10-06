<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bestellversand per Mail (Spec 63): Bestellungen und Stornos gehen direkt vom Server an den Lieferanten,
 * mit Bestell-PDF. Versand über den Mailweg der Host-App (Laravel Mail, z. B. Postmark wie demo/office).
 *
 *  - team_settings: Versandart (mailprogramm = bisher mailto | server) + Absender-Angaben
 *  - foodalchemist_order_mails: Protokoll je Versuch (Bestellung/Storno, an, Status, Fehler)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            $table->string('bestellversand')->default('mailprogramm');   // mailprogramm | server
            $table->string('bestellversand_absender_name')->nullable();  // z. B. „Broich Catering Einkauf"
            $table->string('bestellversand_antwort_an')->nullable();     // leer = Mail des Bestellers
            $table->string('bestellversand_kopie_an')->nullable();       // Kopie (BCC) ans Team, kommagetrennt
            $table->text('bestellversand_signatur')->nullable();
            // Vorlagen mit Platzhaltern ({lieferant} {referenz} {positionen} {liefertermin} {summe} {team} {besteller});
            // leer = Standard-Wortlaut (identisch zum mailto-Weg)
            $table->string('bestellversand_betreff_bestellung')->nullable();
            $table->text('bestellversand_text_bestellung')->nullable();
            $table->string('bestellversand_betreff_storno')->nullable();
            $table->text('bestellversand_text_storno')->nullable();
        });

        Schema::create('foodalchemist_order_mails', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->foreignId('order_id')->constrained('foodalchemist_orders')->cascadeOnDelete();
            $table->string('typ');                       // bestellung | storno
            $table->string('an');
            $table->string('kopie_an')->nullable();
            $table->string('antwort_an')->nullable();
            $table->string('betreff');
            $table->string('status')->default('geplant'); // geplant | versendet | fehlgeschlagen
            $table->text('fehler')->nullable();
            $table->unsignedSmallInteger('versuche')->default(0);
            $table->unsignedBigInteger('ausgeloest_von')->nullable(); // user_id
            $table->timestamp('versendet_am')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['order_id', 'typ'], 'fa_order_mails_order_typ_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_order_mails');
        Schema::table('foodalchemist_team_settings', function (Blueprint $table) {
            $table->dropColumn([
                'bestellversand', 'bestellversand_absender_name', 'bestellversand_antwort_an',
                'bestellversand_kopie_an', 'bestellversand_signatur',
                'bestellversand_betreff_bestellung', 'bestellversand_text_bestellung',
                'bestellversand_betreff_storno', 'bestellversand_text_storno',
            ]);
        });
    }
};
