<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Necta-Abgleich (Bauauftrag 15.10.2026): Lieferanten, Lieferantenartikel und Preise aus necta.one (Raw-API, nur lesend).
 *
 *  - necta_laeufe: ein Lauf (Nachholen/Abgleich, Probe ja/nein) mit Cursor für die Wiederaufnahme, Zählern und Fehlern.
 *  - necta_pruefaelle: Abweichungen, die der Abgleich NICHT still übernimmt (von Hand gepflegtes Feld, Allergen-Änderung).
 *  - necta_stand: je Objekt der zuletzt übernommene Necta-Stand der gespiegelten Felder. Weicht der FA-Wert davon ab, wurde er
 *    von Hand gepflegt → Prüffall statt Überschreiben.
 *  - price_history: unser Stand einer Preiszeile vor jedem Update aus Necta (Kostenverlauf). Nie löschen.
 *
 * Bekannte Grenze: `legacy_id` (Necta-ID) ist auf suppliers/supplier_items/prices global unique — genügt für EINE Verbindung.
 * Mit den Catering-Verbindungen braucht es den Schlüssel (Verbindung, legacy_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_necta_laeufe')) {
            Schema::create('foodalchemist_necta_laeufe', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('verbindung_id')->index()->comment('integration_connections.id');
                $table->unsignedBigInteger('team_id')->nullable()->index()->comment('Team der Preise (Master bzw. Team der Verbindung)');
                $table->string('art', 16)->comment('nachholen | abgleich');
                $table->boolean('probe')->default(false);
                $table->string('status', 16)->default('laeuft')->comment('laeuft | fertig | fehler | abgebrochen');
                $table->json('optionen')->nullable();
                $table->json('cursor')->nullable()->comment('Wiederaufnahme: Phase, Lieferanten-Index, Seite');
                $table->json('zaehler')->nullable();
                $table->json('beispiele')->nullable()->comment('Probelauf: Beispiele je Art');
                $table->text('fehler')->nullable();
                $table->timestamp('gestartet_at')->nullable();
                $table->timestamp('beendet_at')->nullable();
                $table->unsignedBigInteger('ausgeloest_von')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('foodalchemist_necta_pruefaelle')) {
            Schema::create('foodalchemist_necta_pruefaelle', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('lauf_id')->nullable()->index();
                $table->string('art', 32)->comment('manuell_gepflegt | allergen_aenderung | unbekannter_lieferant | …');
                $table->string('objekt', 32)->comment('supplier | supplier_item | item_allergen | item_declaration | item_nutritional | price');
                $table->unsignedBigInteger('legacy_id')->index()->comment('Necta-ID');
                $table->string('feld', 64)->nullable();
                $table->text('fa_wert')->nullable();
                $table->text('necta_wert')->nullable();
                $table->string('status', 16)->default('offen')->comment('offen | uebernommen | verworfen');
                $table->unsignedBigInteger('erledigt_von')->nullable();
                $table->timestamp('erledigt_at')->nullable();
                $table->timestamps();
                $table->index(['objekt', 'legacy_id', 'feld', 'status'], 'fa_necta_pf_objekt_idx');
            });
        }

        if (! Schema::hasTable('foodalchemist_necta_stand')) {
            Schema::create('foodalchemist_necta_stand', function (Blueprint $table) {
                $table->id();
                $table->string('objekt', 32);
                $table->unsignedBigInteger('legacy_id');
                $table->json('werte')->comment('zuletzt übernommene Necta-Werte der gespiegelten FA-Spalten');
                $table->unsignedBigInteger('lauf_id')->nullable();
                $table->timestamps();
                $table->unique(['objekt', 'legacy_id'], 'fa_necta_stand_objekt_uq');
            });
        }

        if (! Schema::hasTable('foodalchemist_price_history')) {
            Schema::create('foodalchemist_price_history', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('price_id')->index();
                $table->unsignedBigInteger('legacy_id')->nullable()->index()->comment('Necta-Preis-ID');
                $table->unsignedBigInteger('supplier_item_id')->nullable()->index();
                $table->unsignedBigInteger('team_id')->nullable();
                $table->string('status', 16)->nullable();
                $table->decimal('price', 14, 4)->nullable();
                $table->decimal('price_partial', 14, 4)->nullable();
                $table->timestamp('valid_to')->nullable();
                $table->timestamp('status_valid_from')->nullable();
                $table->boolean('is_blocked')->nullable();
                $table->timestamp('change_date')->nullable()->comment('Necta changeDate des abgelösten Stands');
                $table->string('quelle', 32)->default('necta_abgleich');
                $table->unsignedBigInteger('lauf_id')->nullable();
                $table->timestamp('abgeloest_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['foodalchemist_price_history', 'foodalchemist_necta_stand', 'foodalchemist_necta_pruefaelle', 'foodalchemist_necta_laeufe'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
