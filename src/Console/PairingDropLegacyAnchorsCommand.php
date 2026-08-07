<?php

namespace Platform\FoodAlchemist\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Inspire-Umbau — „Graph glatt ziehen" (Dominique 2026-08-06): die alten (Nicht-
 * Inspire) Anker KOMPLETT entfernen, sodass nur noch der gemessene Foodpairing-
 * Inspire-Graph übrig bleibt (2.628 Anker / 279.920 Kanten).
 *
 * Legacy = jeder Anker mit source_path <> 'foodpairing_inspire' (bzw. NULL) — also
 * das alte 1.000er-Vokabular. Das DELETE cascadet (ON DELETE CASCADE) durch:
 * pairing_anchor_edges, recipe_anchor_mappings, gp_anchor_mappings, recipe_pairings,
 * recipe_process_anchors. anchor_taste_vectors + anchor_ingredient_map haben KEINEN
 * FK → verwaiste Zeilen werden explizit nachgeputzt.
 *
 * Ersetzt die separaten erprobt-/kontrast-Wipes (die Cascade nimmt alle alten Kanten
 * mit). Rezept/GP-Anker-Verknüpfungen fallen weg → Remap ist der nachgelagerte Schritt.
 * DESTRUKTIV → vorher DB-Snapshot. Default = Dry-Run. Idempotent (Re-Run löscht 0).
 */
class PairingDropLegacyAnchorsCommand extends Command
{
    protected $signature = 'foodalchemist:pairing-drop-legacy-anchors {--apply : wirklich löschen (sonst Dry-Run)}';

    protected $description = 'Alte Nicht-Inspire-Anker + Cascade entfernen — nur gemessener Inspire-Graph bleibt.';

    private const KEEP = 'foodalchemist_vocab_pairing_anchors';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $legacyIds = DB::table(self::KEEP)
            ->where(fn ($q) => $q->whereNull('source_path')->orWhere('source_path', '<>', 'foodpairing_inspire'))
            ->pluck('id');
        $nLegacy = $legacyIds->count();
        $nInspire = DB::table(self::KEEP)->where('source_path', 'foodpairing_inspire')->count();

        if ($nLegacy === 0) {
            $this->info('Keine Legacy-Anker vorhanden — nichts zu tun (Graph bereits rein Inspire).');

            return self::SUCCESS;
        }
        if ($nInspire === 0) {
            $this->error('ABBRUCH: keine Inspire-Anker (source_path=foodpairing_inspire) gefunden — '
                .'erst `inspire-import --apply`, sonst löscht das ALLES.');

            return self::FAILURE;
        }

        $ids = $legacyIds->all();
        $touchEdges = fn () => DB::table('foodalchemist_pairing_anchor_edges')
            ->whereIn('anchor_a_id', $ids)->orWhereIn('anchor_b_id', $ids)->count();
        $cnt = fn (string $t, string $col = 'anchor_id') => DB::table($t)->whereIn($col, $ids)->count();

        $this->info(($apply ? 'APPLY' : 'DRY-RUN').' — Graph glatt ziehen (nur Inspire behalten)');
        $this->table(['Menge', 'Wert'], [
            ['Legacy-Anker (werden gelöscht)', $nLegacy],
            ['Inspire-Anker (bleiben)', $nInspire],
            ['pairing_anchor_edges (cascade)', $touchEdges()],
            ['recipe_anchor_mappings (cascade)', $cnt('foodalchemist_recipe_anchor_mappings')],
            ['gp_anchor_mappings (cascade)', $cnt('foodalchemist_gp_anchor_mappings')],
            ['recipe_pairings (cascade)', $cnt('foodalchemist_recipe_pairings')],
            ['recipe_process_anchors (cascade)', $cnt('foodalchemist_recipe_process_anchors')],
            ['anchor_taste_vectors (Waisen-Cleanup)', $cnt('foodalchemist_anchor_taste_vectors')],
        ]);

        if (! $apply) {
            $this->line('→ Dry-Run. Mit --apply löschen. VORHER Snapshot! Danach GP/Rezept-Remap.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($ids) {
            // Cascade räumt edges/mappings/pairings/process automatisch ab.
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table(self::KEEP)->whereIn('id', $chunk)->delete();
            }
            // Waisen ohne FK explizit nachputzen.
            DB::statement('DELETE FROM foodalchemist_anchor_taste_vectors WHERE anchor_id NOT IN (SELECT id FROM '.self::KEEP.')');
            DB::statement('DELETE FROM foodalchemist_anchor_ingredient_map WHERE anchor_id IS NOT NULL AND anchor_id NOT IN (SELECT id FROM '.self::KEEP.')');
        });

        $rest = DB::table(self::KEEP)->count();
        $restEdges = DB::table('foodalchemist_pairing_anchor_edges')->count();
        $this->info("Fertig. Anker jetzt: {$rest} · Kanten: {$restEdges}. Danach computed re-projizieren + embed + Remap.");

        return self::SUCCESS;
    }
}
