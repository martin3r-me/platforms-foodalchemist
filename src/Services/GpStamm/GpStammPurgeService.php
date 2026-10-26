<?php

namespace Platform\FoodAlchemist\Services\GpStamm;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\Ai\PoolEmbeddingService;
use Platform\FoodAlchemist\Services\PlattformAdminPruefung;
use Platform\Core\Models\User;

/**
 * GP-Stamm-Purge (Plan_Neuaufbau Schritt 4, Punkt 2): löscht ALLE globalen GPs (`team_id IS NULL`, auch soft-gelöschte) samt Kindern,
 * damit der neue Stamm per `gp-paket-import` ohne uuid-UNIQUE-Kollision (gilt auch für Soft-Deletes) und ohne Dubletten kommt.
 *
 * Grundsätze:
 *  - NUR `team_id IS NULL`. Team-eigene GPs und ihre Kinder bleiben unberührt.
 *  - Lieferantenartikel (`supplier_items`) samt Allergenen/Preisen werden NIE angefasst; nur ihre `supplier_item_structures`-Zeilen an den globalen GPs.
 *  - Riegel: Abbruch (nichts gelöscht), wenn an den globalen GPs noch Rezepte, Favoriten, Mandanten-Overrides, Bestellungen, Lager/Inventur,
 *    Bestellvorlagen, Lieferscheine, Präsentations-Zubehör oder -Gefäße hängen. Der Trockenlauf zählt je Tabelle.
 *  - Ein Apply läuft in EINER Transaktion; die Vektoren (Qdrant) werden erst NACH dem Commit über den Embedding-Dienst gelöscht.
 */
class GpStammPurgeService
{
    use PlattformAdminPruefung;

    /** Riegel: Tabellen mit gp_id-Verweis auf globale GPs. Treffer > 0 ⇒ Abbruch. [Tabelle, Spalte, Beschreibung] */
    public const RIEGEL = [
        ['foodalchemist_recipe_ingredients', 'gp_id', 'Rezeptzutaten (Rezepte)'],
        ['foodalchemist_recipe_presentations', 'vessel_gp_id', 'Präsentations-Gefäß'],
        ['foodalchemist_recipe_presentation_zubehoer', 'gp_id', 'Präsentations-Zubehör (RESTRICT)'],
        ['foodalchemist_team_favoriten', 'gp_id', 'Favoriten (Team-Overlay)'],
        ['foodalchemist_gp_team_overrides', 'gp_id', 'Mandanten-Overrides'],
        ['foodalchemist_order_lines', 'gp_id', 'Bestellzeilen'],
        ['foodalchemist_purchase_transactions', 'gp_id', 'Einkaufsbuchungen'],
        ['foodalchemist_order_template_lines', 'gp_id', 'Bestellvorlagen'],
        ['foodalchemist_delivery_note_lines', 'gp_id', 'Lieferschein-Zeilen'],
        ['foodalchemist_inventory_movements', 'gp_id', 'Lagerbewegungen'],
        ['foodalchemist_inventory_stocks', 'gp_id', 'Lagerbestände'],
        ['foodalchemist_inventory_count_lines', 'gp_id', 'Inventur-Zeilen'],
        ['foodalchemist_storage_bin_items', 'gp_id', 'Lagerplatz-Inhalte'],
        ['foodalchemist_gp_lagerartikel', 'gp_id', 'GP-Lagerartikel'],
    ];

    /** Kind-Tabellen OHNE Fremdschlüssel (sonst Waisen): explizit löschen. */
    public const OHNE_FK = [
        ['foodalchemist_gp_forms', ['gp_id']],
        ['foodalchemist_gp_textures', ['gp_id']],
        ['foodalchemist_gp_taste_vectors', ['gp_id']],
        ['foodalchemist_abgleich_log', ['gp_id', 'ausgefuehrt_gp_id']],
    ];

    /** Kinder mit ON DELETE CASCADE: werden explizit gelöscht (damit das Protokoll sie zählt). */
    public const CASCADE = [
        ['foodalchemist_gp_anchor_mappings', 'gp_id'],
        ['foodalchemist_gp_count_unit_defaults', 'gp_id'],
        ['foodalchemist_gp_la_preferences', 'gp_id'],
        ['foodalchemist_match_proposals', 'gp_id'],
        ['foodalchemist_bulk_gp_proposals', 'gp_id'],
    ];

    private const GPS = 'foodalchemist_gps';

    private const STRUKTUR = 'foodalchemist_supplier_item_structures';

    public function __construct(private readonly PoolEmbeddingService $embedding) {}

    private function auswahl(): \Illuminate\Database\Query\Builder
    {
        return DB::table(self::GPS)->whereNull('team_id')->select('id');
    }

    private function tabelleDa(string $t): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable($t);
    }

    /**
     * Trockenlauf-Zählung (liest nur).
     *
     * @return array{gps_global: int, gps_global_aktiv: int, gps_global_soft_geloescht: int, gps_team_eigen: int, riegel: array<string, int>, riegel_treffer: int, ohne_fk: array<string, int>, cascade: array<string, int>, strukturen: int, selbstverweise: int, supplier_items_gesamt: int}
     */
    public function zaehlen(): array
    {
        $global = DB::table(self::GPS)->whereNull('team_id');
        $r = [
            'gps_global' => (clone $global)->count(),
            'gps_global_aktiv' => (clone $global)->whereNull('deleted_at')->count(),
            'gps_global_soft_geloescht' => (clone $global)->whereNotNull('deleted_at')->count(),
            'gps_team_eigen' => DB::table(self::GPS)->whereNotNull('team_id')->count(),
            'riegel' => [], 'riegel_treffer' => 0, 'ohne_fk' => [], 'cascade' => [],
        ];
        foreach (self::RIEGEL as [$tab, $col, $text]) {
            $n = $this->tabelleDa($tab) ? DB::table($tab)->whereIn($col, $this->auswahl())->count() : 0;
            $r['riegel']["{$tab}.{$col}"] = $n;
            $r['riegel_treffer'] += $n;
        }
        foreach (self::OHNE_FK as [$tab, $cols]) {
            $r['ohne_fk'][$tab] = $this->tabelleDa($tab)
                ? DB::table($tab)->where(function ($q) use ($cols) {
                    foreach ($cols as $c) {
                        $q->orWhereIn($c, $this->auswahl());
                    }
                })->count() : 0;
        }
        foreach (self::CASCADE as [$tab, $col]) {
            $r['cascade'][$tab] = $this->tabelleDa($tab) ? DB::table($tab)->whereIn($col, $this->auswahl())->count() : 0;
        }
        $r['strukturen'] = DB::table(self::STRUKTUR)->whereIn('gp_id', $this->auswahl())->count();
        $r['selbstverweise'] = DB::table(self::GPS)->where(fn ($q) => $q->whereIn('derivat_von_gp_id', $this->auswahl())->orWhereIn('merged_into_id', $this->auswahl()))->count();
        $r['supplier_items_gesamt'] = DB::table('foodalchemist_supplier_items')->count();

        return $r;
    }

    /** @return list<string> die Riegel-Tabellen mit Treffern (leer = frei) */
    public function riegelTreffer(array $zaehlung): array
    {
        return array_keys(array_filter($zaehlung['riegel'], fn ($n) => $n > 0));
    }

    /**
     * Apply: Riegel prüfen, in EINER Transaktion löschen, danach Vektoren.
     *
     * @return array<string, mixed> Protokoll (Zähler je Tabelle)
     *
     * @throws \RuntimeException wenn ein Riegel greift (dann ist NICHTS gelöscht)
     */
    public function ausfuehren(?User $user, bool $vektoren = true): array
    {
        $this->nurPlattformAdmin($user, 'GP-Stamm-Purge');
        $start = microtime(true);
        $vorher = $this->zaehlen();
        if ($vorher['riegel_treffer'] > 0) {
            $tabs = implode(', ', array_map(fn ($k) => $k.'='.$vorher['riegel'][$k], $this->riegelTreffer($vorher)));
            throw new \RuntimeException("Riegel: an globalen GPs hängen noch Daten ({$tabs}). Nichts gelöscht.");
        }
        $ids = DB::table(self::GPS)->whereNull('team_id')->pluck('id')->map(fn ($v) => (int) $v)->all();
        $geloescht = [];

        DB::transaction(function () use (&$geloescht, $ids) {
            // 1) Selbstverweise (Derivat, merged_into) auf die globalen GPs lösen (auch von Team-GPs: FK wäre SET NULL).
            //    MySQL verbietet UPDATE mit Unterabfrage auf dieselbe Tabelle (Fehler 1093, io 26.10.) → ID-Liste in Chargen.
            $geloescht['selbstverweise_geloest'] = 0;
            foreach (array_chunk($ids, 1000) as $charge) {
                $geloescht['selbstverweise_geloest'] += DB::table(self::GPS)
                    ->where(fn ($q) => $q->whereIn('derivat_von_gp_id', $charge)->orWhereIn('merged_into_id', $charge))
                    ->update(['derivat_von_gp_id' => null, 'merged_into_id' => null]);
            }
            // 2) Kind-Tabellen ohne FK
            foreach (self::OHNE_FK as [$tab, $cols]) {
                $geloescht[$tab] = $this->tabelleDa($tab) ? DB::table($tab)->where(function ($q) use ($cols) {
                    foreach ($cols as $c) {
                        $q->orWhereIn($c, $this->auswahl());
                    }
                })->delete() : 0;
            }
            // 3) Struktur-Zeilen der globalen GPs (supplier_items selbst bleiben unberührt)
            $geloescht[self::STRUKTUR] = DB::table(self::STRUKTUR)->whereIn('gp_id', $this->auswahl())->delete();
            // 4) CASCADE-Kinder (explizit, damit gezählt)
            foreach (self::CASCADE as [$tab, $col]) {
                $geloescht[$tab] = $this->tabelleDa($tab) ? DB::table($tab)->whereIn($col, $this->auswahl())->delete() : 0;
            }
            // 5) GPs hart (auch soft-gelöschte; uuid-UNIQUE)
            $geloescht[self::GPS] = DB::table(self::GPS)->whereNull('team_id')->delete();
        });

        $vek = ['versucht' => 0, 'fehler' => 0];
        if ($vektoren) {
            foreach ($ids as $id) {
                $vek['versucht']++;
                try {
                    $this->embedding->deleteGp($this->embedding->globalTeamId(), $id, null);   // Partition „global“
                } catch (\Throwable) {
                    $vek['fehler']++;
                }
            }
        }

        return [
            'zeitpunkt' => now()->toIso8601String(), 'dauer_s' => round(microtime(true) - $start, 2),
            'vorher' => $vorher, 'geloescht' => $geloescht, 'vektoren' => $vek, 'gp_ids' => $ids,
            'nachher' => ['gps_global' => DB::table(self::GPS)->whereNull('team_id')->count(), 'gps_team_eigen' => DB::table(self::GPS)->whereNotNull('team_id')->count(),
                'supplier_items_gesamt' => DB::table('foodalchemist_supplier_items')->count()],
        ];
    }
}
