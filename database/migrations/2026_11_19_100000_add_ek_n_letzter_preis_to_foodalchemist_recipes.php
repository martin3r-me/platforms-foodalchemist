<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Letzter Preis für eingestellte Artikel (Dominique 27.10.2026): wie viele bepreiste Zutaten des Rezept-EK aus dem letzten bekannten Preis eines in Necta
 * eingestellten Artikels gerechnet sind — Grundlage des Hinweises „EK enthält n letzte Preise eingestellter Artikel“. NULL = noch nicht gerechnet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foodalchemist_recipes') && ! Schema::hasColumn('foodalchemist_recipes', 'ek_n_letzter_preis')) {
            Schema::table('foodalchemist_recipes', fn (Blueprint $t) => $t->unsignedSmallInteger('ek_n_letzter_preis')->nullable()->after('ek_n_ingredients_priced')
                ->comment('bepreiste Zutaten mit letztem Preis eines eingestellten Artikels'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('foodalchemist_recipes', 'ek_n_letzter_preis')) {
            Schema::table('foodalchemist_recipes', fn (Blueprint $t) => $t->dropColumn('ek_n_letzter_preis'));
        }
    }
};
