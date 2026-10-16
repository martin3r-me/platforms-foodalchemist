<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\FoodAlchemist\Support\GeschirrRueckbau;

/**
 * Rückbau der Geschirr-DB (Dominique 2026-10-16): Geschirr ist ein normaler Non-Food-Artikel, Leihfirmen sind Lieferanten der Gruppe Verleih, das Gefäß hängt
 * an der Darreichung. Die Zuordnungs-Spalten (Slots, Paket-Posten, Standard-Geschirr der Darreichung) und die beiden Tabellen fallen.
 *
 * RIEGEL: vor dem Drop wird gelesen, ob noch Zeilen/Werte da sind ({@see GeschirrRueckbau}); dann bricht die Migration mit klarer Meldung ab.
 * Auf SQLite (nur die Testbasis) bleiben die Spalten und die beiden leeren Tabellen stehen (nichts liest sie): ein Spalten-Drop dort baut die Tabelle neu und
 * zerstört den partiellen Ein-Standard-Index, und die Tabellen können nicht fallen, solange die Darreichungs-Spalte ihre FK-Definition trägt — dieselbe bewusste
 * Asymmetrie wie in 2026_09_04_000014.
 */
return new class extends Migration
{
    public function up(): void
    {
        GeschirrRueckbau::pruefeOderAbbrechen();

        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        foreach (GeschirrRueckbau::SPALTEN as $tabelle => $spalten) {
            if (! Schema::hasTable($tabelle)) {
                continue;
            }
            foreach (array_keys($spalten) as $spalte) {
                if (! Schema::hasColumn($tabelle, $spalte)) {
                    continue;
                }
                if ($sqlite) {
                    DB::table($tabelle)->update([$spalte => null]);   // ohne Drop: nichts mehr gesetzt (der Riegel hat es schon geprüft)

                    continue;
                }
                try {
                    Schema::table($tabelle, fn (Blueprint $t) => $t->dropForeign([$spalte]));
                } catch (\Throwable) {
                    // kein FK an dieser Spalte (Slots/Posten führen nur einen Index) — kein Fehlerfall
                }
                Schema::table($tabelle, fn (Blueprint $t) => $t->dropColumn($spalte));
            }
        }
        // Auf SQLite (nur die Testbasis) bleiben die beiden leeren Tabellen stehen: die Darreichungs-Spalte trägt dort noch ihre FK-Definition auf
        // `tableware_items`, und SQLite verweigert jeden INSERT in `recipe_presentations`, sobald die Elterntabelle fehlt.
        if (! $sqlite) {
            foreach (array_keys(GeschirrRueckbau::TABELLEN) as $tabelle) {
                Schema::dropIfExists($tabelle);
            }
        }
    }

    public function down(): void
    {
        // Rückbau ist endgültig; die Tabellen entstehen nicht neu (die Daten wären ohnehin leer, der Riegel verlangt das).
    }
};
