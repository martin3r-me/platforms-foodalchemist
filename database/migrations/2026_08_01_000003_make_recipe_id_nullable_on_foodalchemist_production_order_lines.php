<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 30 E1 — `recipe_id` wird nullable: freie Positionen (origin=manual) haben kein Rezept.
 *
 * BEWUSST EIGENE MIGRATION, getrennt von den additiven Spalten. Grund: das hier ist die einzige
 * nicht-additive Schema-Änderung des ganzen Vorhabens und die einzige echte Risikostelle.
 * In den ~160 Migrationen dieses Moduls gibt es KEIN einziges `->change()`. Auf SQLite (Testsuite,
 * `:memory:`) baut Laravel die Tabelle dafür intern neu auf — geht dabei ein Index oder ein
 * Fremdschlüssel verloren, schlägt NICHTS fehl, es fällt erst Wochen später auf.
 * Deshalb: eigene Migration (einzeln zurücknehmbar) + `ProduktionSchemaTest`, der Nullability,
 * Cascade und beide Indizes gegen die echte Datenbank nachmisst.
 *
 * Der Unique-Index `(production_order_id, recipe_id)` nagelt die Invariante fest, auf der der
 * Overlay-Restore beruht: die Explosion erzeugt höchstens EINE Zeile je Rezept und Auftrag.
 * Er trägt nur, weil
 *   (a) `computed`-Zeilen IMMER hart gelöscht werden (kein Soft-Delete-Tombstone, den MySQL
 *       mit in die Eindeutigkeit zöge) und
 *   (b) `manual`-Zeilen `recipe_id = NULL` haben — und NULL ≠ NULL, in MySQL wie in SQLite,
 *       also sind beliebig viele freie Positionen erlaubt.
 * Beide Bedingungen sind Regeln des `ProductionOrderService`, keine DB-Garantien.
 */
return new class extends Migration
{
    private const UNIQUE = 'fa_prod_lines_order_recipe_uq';

    public function up(): void
    {
        if (! Schema::hasTable('foodalchemist_production_order_lines')) {
            return;
        }

        Schema::table('foodalchemist_production_order_lines', function (Blueprint $table) {
            $table->foreignId('recipe_id')->nullable()->change();
        });

        // ⚠️ Soft-gelöschte Zeilen blockieren den Unique-Index: `deleted_at` ist NICHT Teil des
        // Schlüssels, ein Grabstein belegt den Platz also weiter. Beim Bau gefunden: genau ein
        // Rest vom 2026-07-22 aus einem frühen Code-Pfad, der noch soft-löschte.
        // Produktionszeilen sind per Definition EPHEMERE Snapshots (sie werden bei jeder
        // Ziel-Änderung neu erzeugt) — ein soft-gelöschter Snapshot hat keinen Wert und ist
        // für die App ohnehin unsichtbar. Deshalb: hart weg, aber gezählt und gemeldet.
        $grabsteine = DB::table('foodalchemist_production_order_lines')->whereNotNull('deleted_at')->count();
        if ($grabsteine > 0) {
            DB::table('foodalchemist_production_order_lines')->whereNotNull('deleted_at')->delete();
            echo "  Spec 30: {$grabsteine} soft-gelöschte Produktionszeilen entfernt (blockierten den Unique-Index).\n";
        }

        // Jetzt erst prüfen: bleiben ECHTE Dubletten übrig, darf der Index nicht gesetzt werden.
        $dubletten = DB::table('foodalchemist_production_order_lines')
            ->select('production_order_id', 'recipe_id')
            ->whereNotNull('recipe_id')
            ->groupBy('production_order_id', 'recipe_id')
            ->havingRaw('COUNT(*) > 1')->count();
        if ($dubletten > 0) {
            throw new \RuntimeException(
                "Spec 30: {$dubletten} echte Auftrag/Rezept-Dubletten in production_order_lines — "
                . 'der Unique-Index kann nicht gesetzt werden. Erst je betroffenem Auftrag neu rechnen.'
            );
        }

        if (! $this->hatIndex(self::UNIQUE)) {
            Schema::table('foodalchemist_production_order_lines', function (Blueprint $table) {
                $table->unique(['production_order_id', 'recipe_id'], self::UNIQUE);
            });
        }
    }

    public function down(): void
    {
        if ($this->hatIndex(self::UNIQUE)) {
            Schema::table('foodalchemist_production_order_lines', function (Blueprint $table) {
                $table->dropUnique(self::UNIQUE);
            });
        }
        // recipe_id bleibt nullable — ein Rückbau würde freie Positionen unrettbar machen.
    }

    /** Index-Existenz DB-portabel prüfen (SQLite-Testsuite vs. MySQL demo). */
    private function hatIndex(string $name): bool
    {
        $treiber = DB::connection()->getDriverName();

        if ($treiber === 'sqlite') {
            return DB::table('sqlite_master')->where('type', 'index')->where('name', $name)->exists();
        }

        return collect(DB::select('SHOW INDEX FROM foodalchemist_production_order_lines'))
            ->contains(fn ($r) => ($r->Key_name ?? null) === $name);
    }
};
