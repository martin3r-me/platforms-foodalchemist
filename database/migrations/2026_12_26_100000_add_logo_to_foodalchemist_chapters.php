<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Präsentation 2026-11-13: optionales Logo je Kapitel (Foodbook-Kapitel, Angebots-Kapitel, Speisekarten-Rubrik),
 * zusätzlich zu Kapitelbild und Galerie. Nur neue, leere Spalten — Bestand bleibt unverändert.
 */
return new class extends Migration
{
    private const TABELLEN = ['foodalchemist_foodbook_chapters', 'foodalchemist_offer_chapters', 'foodalchemist_menu_card_sections'];

    public function up(): void
    {
        foreach (self::TABELLEN as $tabelle) {
            if (! Schema::hasTable($tabelle)) {
                continue;
            }
            Schema::table($tabelle, function (Blueprint $table) use ($tabelle) {
                if (! Schema::hasColumn($tabelle, 'logo_context_file_id')) {
                    $table->unsignedBigInteger('logo_context_file_id')->nullable();
                }
                if (! Schema::hasColumn($tabelle, 'logo_path')) {
                    $table->string('logo_path')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABELLEN as $tabelle) {
            if (! Schema::hasTable($tabelle)) {
                continue;
            }
            Schema::table($tabelle, function (Blueprint $table) use ($tabelle) {
                foreach (['logo_context_file_id', 'logo_path'] as $col) {
                    if (Schema::hasColumn($tabelle, $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
