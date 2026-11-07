<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 96 · Derivate mit Ausbeute. Nur bei is_derivat = 1 gesetzt, alles nullbar: der Bestand bleibt unberührt.
 *  - derivat_art: nebenprodukt (fällt beim Verarbeiten der Mutter ohnehin an → kein Einkauf, 0 €) | zweck (Mutter wird
 *    gezielt dafür gekauft → EK und Bedarf über die Mutter). NULL = aus dem Namen abgeleitet ({@see \Platform\FoodAlchemist\Support\DerivatArt}).
 *  - derivat_ausbeute_wert + _basis: g_je_stueck (Gramm Derivat aus EINEM Stück Mutter) | prozent (Anteil der Mutter-Masse).
 *  - derivat_ausbeute_quelle: manual | ki | regelwerk. Manuell gewinnt (die KI überschreibt keinen manuellen Wert).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_gps', function (Blueprint $t) {
            $t->string('derivat_art', 20)->nullable();
            $t->decimal('derivat_ausbeute_wert', 10, 2)->nullable();
            $t->string('derivat_ausbeute_basis', 20)->nullable();
            $t->string('derivat_ausbeute_quelle', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_gps', function (Blueprint $t) {
            $t->dropColumn(['derivat_art', 'derivat_ausbeute_wert', 'derivat_ausbeute_basis', 'derivat_ausbeute_quelle']);
        });
    }
};
