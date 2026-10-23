<?php

namespace Platform\FoodAlchemist\Services\GpPaket;

use Illuminate\Support\Facades\DB;
use Platform\FoodAlchemist\Services\GpBereinigenService;

/** GP-Paket-Import · Rückweg (`--zurueck`): ein Protokoll umkehren. Die Admin-/Master-Prüfung macht der aufrufende Service. */
class GpPaketRueckweg
{
    public function __construct(
        private readonly GpBereinigenService $verweise,
        private readonly GpPaketValidator $v,
    ) {}

    /**
     * Ein Protokoll umkehren (umgekehrte Reihenfolge, alles in einer Transaktion). Die GPs des Laufs werden per SoftDelete entfernt — NUR wenn
     * danach nichts Neues an ihnen hängt (Rezept, Lager, Bestellung …); sonst Abbruch mit Liste, nichts wird geändert.
     *
     * @param  array<string,mixed>  $p
     * @return array{zurueck: int, uebersprungen: int}
     *
     * @throws \RuntimeException mit der Liste der neuen Referenzen
     */
    public function zuruecknehmen(array $p): array
    {
        $zurueck = 0;
        $skip = 0;
        DB::transaction(function () use ($p, &$zurueck, &$skip) {
            $ts = now()->toDateTimeString();
            // Anker
            foreach (array_reverse($p['anker'] ?? []) as $a) {
                $vorherIds = array_map(fn ($r) => (int) $r['id'], $a['vorher']);
                DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $a['gp_id'])->whereNotIn('id', $vorherIds ?: [0])->delete();
                foreach ($a['vorher'] as $r) {
                    DB::table('foodalchemist_gp_anchor_mappings')->where('id', $r['id'])->update(array_intersect_key($r, array_flip(['role', 'source', 'ai_confidence', 'ai_reasoning', 'deleted_at', 'team_id'])));
                }
                $zurueck++;
            }
            // Leads (nur wenn noch der protokollierte Stand)
            foreach (array_reverse($p['lead'] ?? []) as $l) {
                $ist = DB::table('foodalchemist_gps')->where('id', $l['gp_id'])->first();
                if ($ist !== null && ($ist->lead_la_supplier_item_id === null ? null : (int) $ist->lead_la_supplier_item_id) === $l['neu']) {
                    DB::table('foodalchemist_gps')->where('id', $l['gp_id'])->update(['lead_la_supplier_item_id' => $l['alt'], 'updated_at' => $ts]);
                    $zurueck++;
                } else {
                    $skip++;
                }
            }
            // Verknüpfungen lösen / auf den alten GP zurück
            foreach (array_reverse($p['verknuepfungen'] ?? []) as $v) {
                $ist = DB::table('foodalchemist_supplier_item_structures')->where('id', $v['structure_id'])->first();
                if ($ist !== null && ($ist->gp_id === null ? null : (int) $ist->gp_id) === $v['gp_neu']) {
                    DB::table('foodalchemist_supplier_item_structures')->where('id', $v['structure_id'])->update(['gp_id' => $v['gp_alt'], 'updated_at' => $ts]);
                    $zurueck++;
                } else {
                    $skip++;
                }
            }
            foreach (array_reverse($p['entknuepft_direkt'] ?? []) as $e) {
                $ist = DB::table('foodalchemist_supplier_item_structures')->where('id', $e['structure_id'])->first();
                if ($ist !== null && $ist->gp_id === null) {
                    DB::table('foodalchemist_supplier_item_structures')->where('id', $e['structure_id'])->update(['gp_id' => $e['gp_alt'], 'updated_at' => $ts]);
                }
            }
            foreach ($p['struktur_reaktiviert'] ?? [] as $s) {
                DB::table('foodalchemist_supplier_item_structures')->where('id', $s['id'])->update(['deleted_at' => $s['deleted_at'], 'gp_id' => $s['gp_alt'], 'updated_at' => $ts]);
            }
            foreach ($p['struktur_neu'] ?? [] as $s) {
                $ist = DB::table('foodalchemist_supplier_item_structures')->where('id', $s['id'])->first();
                if ($ist !== null && $ist->gp_id === null) {
                    DB::table('foodalchemist_supplier_item_structures')->where('id', $s['id'])->delete();
                    $zurueck++;
                } else {
                    $skip++;
                }
            }
            foreach (array_reverse($p['n_las'] ?? []) as $n) {
                DB::table('foodalchemist_gps')->where('id', $n['gp_id'])->update(['n_las_total' => $n['alt']]);
            }
            foreach (array_reverse($p['status_korrektur'] ?? []) as $s) {
                DB::table('foodalchemist_gps')->where('id', $s['gp_id'])->update(['status' => $s['alt']]);
            }
            // Kind-Zeilen
            $tab = ['forms' => 'foodalchemist_gp_forms', 'textures' => 'foodalchemist_gp_textures', 'einheiten' => 'foodalchemist_gp_count_unit_defaults', 'aequi' => 'foodalchemist_component_equivalents'];
            foreach ($p['kinder_neu'] ?? [] as $art => $ids) {
                if ($ids !== []) {
                    DB::table($tab[$art])->whereIn('id', $ids)->delete();
                    $zurueck += count($ids);
                }
            }
            foreach ($p['kinder_geaendert'] ?? [] as $art => $zeilen) {
                foreach ($zeilen as $k) {
                    DB::table($tab[$art])->where('id', $k['id'])->update($k['vorher']);
                    $zurueck++;
                }
            }
            // geänderte GPs zurück (nur wenn noch der protokollierte Stand)
            foreach (array_reverse($p['gps_geaendert'] ?? []) as $g) {
                $ist = (array) DB::table('foodalchemist_gps')->where('id', $g['id'])->first();
                $gleich = true;
                foreach ($g['nachher'] as $c => $v) {
                    if ($this->v->norm($ist[$c] ?? null) !== $this->v->norm($v)) {
                        $gleich = false;
                    }
                }
                if ($gleich) {
                    DB::table('foodalchemist_gps')->where('id', $g['id'])->update($g['vorher'] + ['updated_at' => $ts]);
                    $zurueck++;
                } else {
                    $skip++;
                }
            }
            // neue GPs: nur ohne neue Referenz (Derivat-Kinder des Laufs zählen nicht — zuerst lösen)
            $neueIds = array_map(fn ($g) => (int) $g['id'], $p['gps_neu'] ?? []);
            if ($neueIds !== []) {
                DB::table('foodalchemist_gps')->whereIn('id', $neueIds)->update(['derivat_von_gp_id' => null]);
            }
            $offen = [];
            foreach ($p['gps_neu'] ?? [] as $g) {
                $z = $this->verweise->verweisZahlen((int) $g['id']);
                if ($z !== []) {
                    $offen[] = "{$g['gp_key']} (#{$g['id']}): " . implode(', ', array_map(fn ($t, $n) => "{$t}={$n}", array_keys($z), $z));
                }
            }
            if ($offen !== []) {
                throw new \RuntimeException("Rückweg abgebrochen, nichts geändert — an neuen GPs hängt inzwischen etwas:\n  " . implode("\n  ", $offen));
            }
            foreach ($p['gps_neu'] ?? [] as $g) {
                DB::table('foodalchemist_gps')->where('id', $g['id'])->whereNull('deleted_at')->update(['deleted_at' => $ts, 'updated_at' => $ts]);
                $zurueck++;
            }
        });

        return ['zurueck' => $zurueck, 'uebersprungen' => $skip];
    }
}
