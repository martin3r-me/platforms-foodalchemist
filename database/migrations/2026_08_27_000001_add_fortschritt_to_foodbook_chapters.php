<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kapitel-Fortschritt (Dominique 2026-08-27): manueller 3-Stufen-Status je Foodbook-Kapitel —
 * `offen` | `in_arbeit` | `fertig`. Der/die Planer:in setzt ihn per Dropdown im Board; die
 * `fertig`-Stufe treibt den grünen Punkt im Board + die KPI „Fertig X/Y" oben.
 *
 * Bewusst getrennt von `released_at` (technisches „materialisiert via Kapitel anlegen") und
 * `status` (draft|sent|archived = Versand) — das hier ist der SICHTBARE Bearbeitungs-Fortschritt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_foodbook_chapters')) {
            return;
        }

        Schema::table('foodalchemist_foodbook_chapters', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_foodbook_chapters', 'fortschritt')) {
                $table->string('fortschritt', 12)->default('offen'); // offen|in_arbeit|fertig (Model-Const)
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('foodalchemist_foodbook_chapters')) {
            return;
        }

        Schema::table('foodalchemist_foodbook_chapters', function (Blueprint $table) {
            if (Schema::hasColumn('foodalchemist_foodbook_chapters', 'fortschritt')) {
                $table->dropColumn('fortschritt');
            }
        });
    }
};
