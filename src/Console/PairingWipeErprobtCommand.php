<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Inspire-Umbau 2a Schritt 4 — `erprobt` komplett aus dem Graphen entfernen
 * (ai_dossier + human, Dominique 2026-08-06). Betrifft BEIDE Ebenen:
 *   - foodalchemist_pairing_anchor_edges.type='erprobt'  (Anker-Graph)
 *   - foodalchemist_recipe_pairings.type='erprobt'        (Rezept→Anker-Chips)
 * Andere Typen (aroma/kontrast/verbund/trinitas …) bleiben unberührt.
 * Destruktiv → vorher DB-Snapshot ziehen. Default = Dry-Run, --apply löscht.
 * Idempotent (Re-Run löscht 0).
 */
class PairingWipeErprobtCommand extends Command
{
    protected $signature = 'foodalchemist:pairing-wipe-erprobt {--apply : wirklich löschen (sonst nur Dry-Run)}';

    protected $description = 'Inspire 2a/4: erprobt-Kanten + -Chips löschen (empirisch kein Gold, s. Gate A).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $edges = (int) DB::table('foodalchemist_pairing_anchor_edges')->where('type', 'erprobt')->count();
        $chips = DB::getSchemaBuilder()->hasTable('foodalchemist_recipe_pairings')
            ? (int) DB::table('foodalchemist_recipe_pairings')->where('type', 'erprobt')->count()
            : 0;

        $this->info(($apply ? 'APPLY' : 'DRY-RUN').' — erprobt-Wipe');
        $this->table(['Ebene', 'erprobt-Zeilen'], [
            ['pairing_anchor_edges', $edges],
            ['recipe_pairings (Chips)', $chips],
        ]);

        if (! $apply) {
            $this->line('→ Dry-Run. Mit --apply löschen. VORHER DB-Snapshot ziehen!');

            return self::SUCCESS;
        }

        $delEdges = DB::table('foodalchemist_pairing_anchor_edges')->where('type', 'erprobt')->delete();
        $delChips = DB::getSchemaBuilder()->hasTable('foodalchemist_recipe_pairings')
            ? DB::table('foodalchemist_recipe_pairings')->where('type', 'erprobt')->delete()
            : 0;

        $this->info("Gelöscht: {$delEdges} Kanten, {$delChips} Chips. Danach computed re-projizieren + reweight.");

        return self::SUCCESS;
    }
}
