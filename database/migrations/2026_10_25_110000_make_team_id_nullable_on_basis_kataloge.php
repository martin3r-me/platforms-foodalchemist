<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 82 §13 I (Grundsatz (a)) — Basis-Kataloge dürfen global sein (`team_id` NULL).
 *
 * `serving_forms` (Servierformen) und `component_equivalents` (Komponenten-Äquivalenz) waren `team_id NOT NULL`; Konzepte, Formate,
 * Präsentationen und Abo-Rezepte zeigen auf sie, also müssen sie als Basis global liegen können. NUR die Spalte wird nullable —
 * KEIN Datenumzug (das Heben der Team-6-Zeilen nach global kommt mit dem Stamm-Umzug-Befehl auf dem eigenen Server).
 * Die übrigen Basis-Kataloge (Einheiten, Warengruppen, Kategorien, Klassen, Equipment, Behälter, Regeneration, Vehikel) sind schon nullable.
 */
return new class extends Migration
{
    private const TABELLEN = ['foodalchemist_serving_forms', 'foodalchemist_component_equivalents'];

    public function up(): void
    {
        foreach (self::TABELLEN as $tabelle) {
            if (Schema::hasTable($tabelle) && Schema::hasColumn($tabelle, 'team_id')) {
                Schema::table($tabelle, function (Blueprint $table) {
                    $table->unsignedBigInteger('team_id')->nullable()->change();
                });
            }
        }
    }

    public function down(): void
    {
        // Bewusst leer: NOT NULL zurück ginge nur, solange keine globale Zeile existiert — sonst Datenverlust/Fehler.
    }
};
