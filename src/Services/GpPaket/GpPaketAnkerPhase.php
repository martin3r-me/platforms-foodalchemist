<?php

namespace Platform\FoodAlchemist\Services\GpPaket;

use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Services\PairingService;
use Platform\FoodAlchemist\Support\GpPaketZaehler;
use Platform\FoodAlchemist\Support\MasterTeam;
use Platform\FoodAlchemist\Support\TeamScope;

/** GP-Paket-Import · Anker-Phase (Pairing-Anker je GP: Plan + Schreiben). */
class GpPaketAnkerPhase
{
    public function __construct(
        private readonly PairingService $pairing,
        private readonly GpPaketValidator $v,
    ) {}

    // ── Anker ──────────────────────────────────────────────────────────────────────────────────────

    /**
     * Gleiche Regeln wie `foodalchemist.gp_anchors.IMPORT`: Slug muss auflösen, source ≤ GP_ANKER_SOURCE_MAX, höchstens CAP_GP je GP,
     * `ersetze_alle` je GP (die Paket-Zeilen ersetzen den Bestand).
     *
     * @param  array<string,array<string,mixed>>  $P
     * @return array<string,array<string,mixed>>
     */
    public function planeAnker(array &$P, array $paket, array $ctx, GpPaketZaehler $z, array &$listen, ?string $nurWg, array $alleKeys): array
    {
        $master = MasterTeam::id();
        $ketteIds = $master !== null && ($t = Team::find($master)) !== null ? TeamScope::ancestryIds($t) : [];
        $ankerIds = DB::table('foodalchemist_vocab_pairing_anchors')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('team_id')->orWhereIn('team_id', $ketteIds))->pluck('id', 'slug')->all();
        $je = [];
        foreach ($paket['anker'] as $r) {
            $k = (string) ($r['gp_key'] ?? '');
            $slug = trim((string) ($r['anchor_slug'] ?? ''));
            if ($nurWg !== null && isset($alleKeys[$k]) && $this->v->wgCode($alleKeys[$k]['commodity_group_code'] ?? null) !== $nurWg) {
                continue;
            }
            $wg = $P[$k]['wg'] ?? '--';
            $ab = function (string $grund) use ($z, &$listen, $wg, $k, $slug) {
                $z->inc($wg, 'anker_abgelehnt');
                $listen['anker_abgelehnt'][] = ['wg' => $wg, 'gp_key' => $k, 'anchor_slug' => $slug, 'grund' => $grund];
            };
            if (! isset($P[$k])) {
                $ab(isset($alleKeys[$k]) ? 'GP ist abgelehnt (siehe gp_abgelehnt.csv)' : 'GP nicht im Paket');

                continue;
            }
            if (! isset($ankerIds[$slug])) {
                $ab('Anker-Slug unbekannt/nicht sichtbar');

                continue;
            }
            $source = trim((string) ($r['source'] ?? ''));
            if ($source === '') {
                $ab('source ist Pflicht');

                continue;
            }
            if (mb_strlen($source) > PairingService::GP_ANKER_SOURCE_MAX) {
                $ab('source zu lang (max. ' . PairingService::GP_ANKER_SOURCE_MAX . ')');

                continue;
            }
            $role = in_array($r['role'] ?? 'kern', ['kern', 'neben'], true) ? ($r['role'] ?? 'kern') : 'kern';
            $conf = isset($r['ai_confidence']) && is_numeric($r['ai_confidence']) ? max(0.0, min(1.0, (float) $r['ai_confidence'])) : null;
            if (! isset($je[$k][$slug]) && count($je[$k] ?? []) >= PairingService::CAP_GP) {
                $ab('Limit erreicht: max ' . PairingService::CAP_GP . ' Anker pro GP');

                continue;
            }
            $je[$k][$slug] = ['slug' => $slug, 'anchor_id' => (int) $ankerIds[$slug], 'role' => $role, 'source' => $source, 'conf' => $conf, 'reasoning' => $r['ai_reasoning'] ?? null];
        }
        $ids = [];
        foreach ($je as $k => $_) {
            if ($P[$k]['id'] !== null) {
                $ids[] = $P[$k]['id'];
            }
        }
        $io = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table('foodalchemist_gp_anchor_mappings')->whereIn('gp_id', $chunk)->whereNull('deleted_at')->get() as $r) {
                $io[$r->gp_id][(int) $r->anchor_id] = (array) $r;
            }
        }
        $out = [];
        foreach ($je as $k => $rows) {
            $wg = $P[$k]['wg'];
            $z->inc($wg, 'anker_gps');
            $ist = $P[$k]['id'] !== null ? ($io[$P[$k]['id']] ?? []) : [];
            $gleich = count($ist) === count($rows);
            foreach ($rows as $r) {
                $x = $ist[$r['anchor_id']] ?? null;
                if ($x === null || $x['role'] !== $r['role'] || $x['source'] !== $r['source'] || $this->v->norm($x['ai_confidence']) !== $this->v->norm($r['conf']) || ($x['ai_reasoning'] ?? null) !== $r['reasoning']) {
                    $gleich = false;
                }
            }
            $out[$k] = ['rows' => array_values($rows), 'aktion' => $gleich ? 'unveraendert' : 'schreiben'];
            $z->inc($wg, $gleich ? 'anker_unveraendert' : 'anker_neu', count($rows));
        }

        return $out;
    }

    /**
     * Anker schreiben (über PairingService wie gp_anchors.IMPORT).
     *
     * @param  array<string,array<string,mixed>>  $P
     * @param  array<string,array<string,mixed>>  $ankerGp
     * @param  array<string,mixed>  $proto
     * @param  list<array>  $listeAbgelehnt
     */
    public function schreibe(array $P, array $ankerGp, Team $master, array &$proto, array &$listeAbgelehnt): void
    {
        foreach ($ankerGp as $key => $a) {
            $gpId = $P[$key]['id'] ?? null;
            if ($gpId === null || $a['aktion'] === 'unveraendert') {
                continue;
            }
            $vorher = DB::table('foodalchemist_gp_anchor_mappings')->where('gp_id', $gpId)->get()->map(fn ($r) => (array) $r)->all();
            $this->pairing->clearGpAnker($master, $gpId);
            $abgelehnt = 0;
            foreach ($a['rows'] as $r) {
                try {
                    $this->pairing->setGpAnker($master, $gpId, $r['anchor_id'], $r['role'], $r['source'], $r['conf'], $r['reasoning']);
                } catch (\RuntimeException $e) {
                    $abgelehnt++;
                    $listeAbgelehnt[] = ['wg' => $P[$key]['wg'], 'gp_key' => $key, 'anchor_slug' => $r['slug'], 'grund' => $e->getMessage()];
                }
            }
            $proto['anker'][] = ['gp_id' => $gpId, 'gp_key' => $key, 'vorher' => $vorher];
        }
    }
}
