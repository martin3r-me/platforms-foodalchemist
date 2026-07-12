<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Taxonomie-Schnitt (Dominique 2026-07-12): nur noch drei zeitlose Pairing-Typen —
 * aroma / kontrast / erprobt. „klassisch" und „modern" waren Ära-Etiketten (was heute
 * modern ist, ist morgen klassisch) und werden zu `erprobt` verschmolzen (= in der
 * Küche bewährt, egal aus welcher Zeit).
 *
 * Betrifft BEIDE Pairing-Ebenen:
 *   - foodalchemist_pairing_anchor_edges.type  (Anker-Graph)
 *   - foodalchemist_recipe_pairings.type        (Rezept→Anker-Chips)
 * NICHT: Rezept-Niveau (haute/gehoben/klassisch) und KI-Stile — anderer Kontext.
 *
 * Idempotent. Dedup: würde beides→erprobt die jeweilige UNIQUE-Kombination verletzen
 * (edges: a,b,type · recipe_pairings: recipe_id,anchor_id,type), fällt die schwächere
 * modern-Zeile weg (klassisch/1.0 war ohnehin stärker). Portabel (SQLite + MySQL):
 * Kollisions-IDs selektieren, dann per ID löschen — kein Multi-Table-DELETE.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->mergeTypes('foodalchemist_pairing_anchor_edges', ['anchor_a_id', 'anchor_b_id']);
        $this->mergeTypes('foodalchemist_recipe_pairings', ['recipe_id', 'anchor_id']);
    }

    public function down(): void
    {
        // Nicht sauber umkehrbar (klassisch/modern-Unterscheidung verloren). Bewusst No-Op.
    }

    /** modern-Dublette (gleicher Key + klassisch-Geschwister) verwerfen, dann klassisch+modern→erprobt. */
    private function mergeTypes(string $table, array $keyCols): void
    {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            return;
        }
        $dupIds = DB::table($table.' as m')
            ->join($table.' as k', function ($join) use ($keyCols) {
                foreach ($keyCols as $col) {
                    $join->on("k.$col", '=', "m.$col");
                }
                $join->where('k.type', '=', 'klassisch');
            })
            ->where('m.type', '=', 'modern')
            ->pluck('m.id');

        if ($dupIds->isNotEmpty()) {
            DB::table($table)->whereIn('id', $dupIds)->delete();
        }

        DB::table($table)->whereIn('type', ['klassisch', 'modern'])->update(['type' => 'erprobt']);
    }
};
