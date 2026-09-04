<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reparatur (Befund auf Echtdaten, demo 2026-09-04) zu 2026_09_04_000010.
 *
 * ZWEI Fehler, beide erst am echten Bestand sichtbar:
 *
 * 1. Der Backfill schrieb `max_fuellgewicht_kg = 15` an JEDE GN-Zeile — auch an ein GN 1/9 mit
 *    0,6 l. Das ist folgenlos in der Rechnung (min() nimmt ohnehin den kleineren Wert), aber es
 *    ist Rauschen: wenn an fünfzehn Zeilen ein Deckel steht, der nie greift, glaubt niemand mehr
 *    dem einen, der greift. Der Deckel bleibt nur, wo er binden kann.
 *
 * 2. Die 20-mm-Formate bekamen KEIN Nennvolumen (der Handel veröffentlicht für Einlegeschalen
 *    keins) — und fielen damit still auf die Kantenrechnung zurück, die bei konischen Behältern
 *    rund ein Fünftel zu hoch liegt. Bei GN 1/1-20 waren es 2,928 l statt ~2,1 l: 38 % daneben.
 *    Der Fallback ist mit diesem Stand aus `BehaelterRechner::nutzvolumenL()` entfernt; diese
 *    Migration räumt nur die Daten hinterher auf, damit `bemessbar: false` auch stimmt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_vocab_containers')
            || ! Schema::hasColumn('foodalchemist_vocab_containers', 'max_fuellgewicht_kg')) {
            return;
        }

        // Deckel weg, wo der Behälter ihn physisch nie erreicht.
        DB::table('foodalchemist_vocab_containers')
            ->whereNull('deleted_at')
            ->whereNotNull('max_fuellgewicht_kg')
            ->where(function ($q) {
                $q->whereNull('volumen_l')
                    ->orWhereRaw('volumen_l * COALESCE(nutzfaktor, 0.85) <= max_fuellgewicht_kg');
            })
            ->update(['max_fuellgewicht_kg' => null, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Kein Rückbau: der Zustand vorher war ein Datenfehler, kein Feature.
    }
};
