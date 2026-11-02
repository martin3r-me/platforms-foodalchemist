<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 94 · Eine Anfrage aus dem Konfigurator-Link wird ein normales Angebot (Status anfrage). Das Angebot merkt sich
 * den Konfigurator und die Auswahl des Kunden (Konzepte, Tageszeit, Add-ons, Pax, Preisstand). Additiv/nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('foodalchemist_offers', 'konfigurator_id')) {
            return;
        }
        Schema::table('foodalchemist_offers', function (Blueprint $table) {
            $table->unsignedBigInteger('konfigurator_id')->nullable()->index();
            $table->json('konfiguration_json')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_offers', 'konfigurator_id')) {
            Schema::table('foodalchemist_offers', function (Blueprint $table) {
                $table->dropIndex(['konfigurator_id']);
                $table->dropColumn(['konfigurator_id', 'konfiguration_json']);
            });
        }
    }
};
