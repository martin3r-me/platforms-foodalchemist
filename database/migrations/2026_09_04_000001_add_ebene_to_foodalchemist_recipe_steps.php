<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anleitungs-Ebene an den Schritten (Regelwerk Verkaufsgerichte §3, User-Entscheid 2026-09-04).
 *
 * Bis hierher war das Anrichten das einzige der drei Anleitungs-Ebenen OHNE Bilder und ohne
 * Schrittfolge: ein einzelnes Markdown-Feld (`recipes.plating_text`), in das die KI einen
 * 500-Zeichen-Absatz schrieb. Dabei ist der Teller-Aufbau der visuellste Arbeitsgang von
 * allen — ein Foto des aufgebauten Tellers trägt mehr als jeder Absatz.
 *
 * Statt einer zweiten Foto-Mechanik neben der bestehenden bekommen die Schritte eine Ebene:
 * dieselbe Tabelle, derselbe Editor, derselbe Foto-Pivot, dieselben Karten im Druck — nur
 * zwei Filter darauf. `plating_text` wird damit zum gerenderten Spiegel der Anrichte-Schritte,
 * genau wie `preparation` schon der Spiegel der Produktions-/Fertigstellungs-Schritte ist
 * (EINBAHN, Regelwerk Basisrezepte §9) — Foodbook und Report lesen unverändert das Textfeld.
 *
 * Werte:
 *  - `produktion` (Default): am Basisrezept die Herstellung, am Gericht das Fertigstellen.
 *    Ein Name für beide, weil es dasselbe Feld und derselbe Adressat ist (Küche, nicht Pass).
 *  - `anrichten`: Teller-Aufbau am Pass.
 *
 * Bestand: ALLE existierenden Schritte sind `produktion` — es gab keine anderen. Der Default
 * deckt das ab, ein Backfill ist deshalb nur für die Alt-Zeilen nötig, die vor dem Default
 * angelegt wurden (MySQL setzt den Default bei ADD COLUMN bereits ein; das UPDATE ist die
 * Absicherung für Zeilen mit explizitem NULL).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_recipe_steps')) {
            return;
        }

        if (! Schema::hasColumn('foodalchemist_recipe_steps', 'ebene')) {
            Schema::table('foodalchemist_recipe_steps', function (Blueprint $table) {
                $table->string('ebene', 16)->default('produktion')->after('recipe_id')
                    ->comment('produktion|anrichten — Anleitungs-Ebene (Regelwerk Verkaufsgerichte §3)');
            });

            DB::table('foodalchemist_recipe_steps')->whereNull('ebene')->update(['ebene' => 'produktion']);

            // Die Position ist ab jetzt JE EBENE 1..n — der Lese-Index muss das mitführen,
            // sonst sortiert jede Editor-/Druck-Query über den alten (recipe_id, position).
            Schema::table('foodalchemist_recipe_steps', function (Blueprint $table) {
                $table->index(['recipe_id', 'ebene', 'position'], 'fa_recipe_steps_recipe_ebene_pos_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('foodalchemist_recipe_steps', 'ebene')) {
            return;
        }

        Schema::table('foodalchemist_recipe_steps', function (Blueprint $table) {
            $table->dropIndex('fa_recipe_steps_recipe_ebene_pos_idx');
            $table->dropColumn('ebene');
        });
    }
};
