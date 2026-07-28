<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 22 · H3a — V-047: die Lauf-Zeile bekommt einen Gegenstand.
 *
 * `foodalchemist_bulk_runs` trägt seit 2026-06-12 vier Zähler (`total`/`done`/`failed`
 * plus `status`) und sagt damit, DASS ein Lauf lief — nie, WORAN. Für den
 * Anreicherungs-Autopilot reichte das (die `bulk_proposals` hängen per `run_id` daran
 * und tragen den Kontext), für den Datei-Import aus 13·S1 nicht: welche Datei und
 * welcher Lieferant verarbeitet wurden, steht nach dem Lauf nirgends. `ingest.STATUS`
 * musste das bis heute als `laeufe_hinweis` einräumen.
 *
 * EIN nullbares json-Feld statt n Spezial-Spalten: jede Lauf-Art legt ihre Parameter
 * hinein (Import: Datei + `supplier_id` + `apply`; Review: Pass + Limit; Anreicherung:
 * Schritte + Arbeitsmenge). Additiv, kein Rückbau — die Zähler bleiben, die Werteliste
 * je Art gehört in den jeweiligen Schreiber, nicht in diesen Kommentar (V-020).
 *
 * Das zugehörige Vokabular für `type`/`status` lebt ab hier in den PHP-Enums
 * `Platform\FoodAlchemist\Enums\BulkRunType` bzw. `BulkRunStatus` — bis hierhin stand die
 * erlaubte Werteliste ausschließlich im Migrations-Kommentar von 2026-06-12, der `ingest`,
 * `review` und `enrich_gp` gar nicht kennt.
 *
 * `deleted_at` kommt im selben Zug dazu (V-032): die Tabelle bekommt mit H3a ihr erstes
 * Eloquent-Model, und der Trait-Vertrag des Moduls (`PolicyTest`) verlangt von jedem Model
 * `SoftDeletes`. Gelöscht wird hier nichts — die Spalte ist die Zusicherung, dass ein Lauf
 * auch künftig nicht hart aus der Buchhaltung verschwinden kann.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Spalten einzeln geprüft, nicht als Paket: eine halb angewendete Migration
        // (eine Spalte da, die andere nicht) soll sich beim nächsten Lauf heilen statt
        // beim ersten `hasColumn` auszusteigen.
        Schema::table('foodalchemist_bulk_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('foodalchemist_bulk_runs', 'context')) {
                $table->json('context')->nullable()->after('failed');
            }
            if (! Schema::hasColumn('foodalchemist_bulk_runs', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        $vorhanden = array_values(array_filter(
            ['context', 'deleted_at'],
            fn (string $spalte) => Schema::hasColumn('foodalchemist_bulk_runs', $spalte),
        ));
        if ($vorhanden === []) {
            return;
        }
        Schema::table('foodalchemist_bulk_runs', function (Blueprint $table) use ($vorhanden) {
            $table->dropColumn($vorhanden);
        });
    }
};
