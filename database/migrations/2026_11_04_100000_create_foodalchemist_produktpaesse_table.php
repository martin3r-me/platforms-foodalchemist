<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produktpass am Lieferantenartikel (Bauauftrag Kurator 16.10.2026): PDF/Foto des Produktpasses bzw. Datenblatts,
 * mehrere Versionen mit Datum. Die Datei liegt im Core-Datei-Speicher (ContextFile, wie die Belege); hier steht die
 * Version samt dem, was die KI daraus gelesen hat (`extrakt`: je Feld Wert + Konfidenz) und dem Stand der Übernahme.
 *
 * `allergene_status`: Allergene werden NIE direkt geschrieben — `vorschlag` bis zur Bestätigung (Klick/MCP-Admin).
 * `uebernommen` hält je direkt geschriebenem Feld den alten Wert (Nachvollziehbarkeit, Rückbau).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_produktpaesse')) {
            return;
        }
        Schema::create('foodalchemist_produktpaesse', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->nullable()->index()->comment('Besitzer-Team des Artikels; bei Stamm-Artikeln (team_id NULL) das Master-Team');
            $table->foreignId('supplier_item_id')->constrained('foodalchemist_supplier_items')->cascadeOnDelete();
            $table->unsignedInteger('version')->comment('je Artikel fortlaufend');
            $table->unsignedBigInteger('context_file_id')->nullable()->comment('Core ContextFile (Datei-Speicher)');
            $table->string('original_name')->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('groesse_kb')->nullable();
            $table->string('status', 16)->default('hochgeladen')->index()->comment('hochgeladen | liest | gelesen | fehler | verworfen');
            $table->string('quelle', 16)->default('produktpass')->comment('produktpass | websuche (später)');
            $table->json('extrakt')->nullable()->comment('gelesene Felder: {felder:{key:{wert,konfidenz,einheit,zitat,url}}, allergene:{…}}');
            $table->json('uebernommen')->nullable()->comment('direkt geschriebene Felder mit altem Wert');
            $table->string('allergene_status', 16)->default('keine')->comment('keine | vorschlag | bestaetigt | verworfen');
            $table->unsignedBigInteger('allergene_bestaetigt_von')->nullable();
            $table->timestamp('allergene_bestaetigt_at')->nullable();
            $table->unsignedBigInteger('hochgeladen_von')->nullable();
            $table->timestamp('gelesen_at')->nullable();
            $table->timestamp('uebernommen_at')->nullable();
            $table->text('fehler')->nullable();
            $table->string('ki_modell', 64)->nullable();
            $table->unsignedInteger('tokens')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['supplier_item_id', 'version'], 'fa_produktpass_item_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foodalchemist_produktpaesse');
    }
};
