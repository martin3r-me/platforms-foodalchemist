<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 20 · P0 — benannte Produktionen.
 *
 * V1 (Dominique 2026-07-23): Name + Datum = Identität eines Produktionsauftrags;
 * mehrere Aufträge pro Tag sind ab jetzt erlaubt. `name` wird die Hauptspalte im
 * Browser und der Titel im DetailPanel. Backfill für Bestand: bevorzugt aus
 * `reference` (Anlass), sonst ein sprechendes Datums-Label „Produktion dd.mm.yyyy".
 *
 * Kein Unique-Constraint auf (team, production_date) — der bisherige Ein-Auftrag-je-Tag-
 * Guard fällt bewusst (siehe Dossier V1). Rundung von Sub-Rezept-Ansätzen bleibt NUR
 * innerhalb EINES Auftrags gemeinsam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foodalchemist_production_orders', function (Blueprint $table) {
            $table->string('name')->nullable()->after('reference')->comment('Auftrags-Name (V1: Name+Datum = Identität, Pflichtfeld im Editor)');
        });

        // Backfill Bestand: reference → name; sonst Datums-Label. Zeilenweise, DB-portabel
        // (SQLite-Test-Harness kennt kein COALESCE-über-Format), aber pro Team-Scope irrelevant hier.
        DB::table('foodalchemist_production_orders')
            ->whereNull('name')
            ->orderBy('id')
            ->each(function ($row) {
                $name = $row->reference !== null && trim((string) $row->reference) !== ''
                    ? $row->reference
                    : 'Produktion ' . Carbon::parse($row->production_date)->format('d.m.Y');
                DB::table('foodalchemist_production_orders')->where('id', $row->id)->update(['name' => $name]);
            });
    }

    public function down(): void
    {
        Schema::table('foodalchemist_production_orders', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
