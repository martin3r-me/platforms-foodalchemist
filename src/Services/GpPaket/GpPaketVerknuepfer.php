<?php

namespace Platform\FoodAlchemist\Services\GpPaket;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistItemAllergen;
use Platform\FoodAlchemist\Models\FoodAlchemistItemDeclaration;
use Platform\FoodAlchemist\Services\LeadLaService;
use Platform\FoodAlchemist\Support\DeklarationsProfil;
use Platform\FoodAlchemist\Support\GpPaketZaehler;
use Symfony\Component\Uid\UuidV7;

/**
 * GP-Paket-Import · Artikel-Verknüpfung: Treffer über legacy_id, Reihenfolge (Lead zuerst), Allergen-Guard (Simulation + realer Weg über
 * {@see LeadLaService::verknuepfen()}), Status/Lead-Schätzung, n_las_total und Lead-Entscheid (§4.3).
 */
class GpPaketVerknuepfer
{
    public function __construct(
        private readonly LeadLaService $lead,
        private readonly GpPaketValidator $v,
        private readonly GpPaketPreisSicht $preis,
    ) {}

    /** Die Preissicht dieser Instanz (der Orchestrator setzt sie je Lauf; der Container liefert je Klasse eine eigene Instanz). */
    public function preisSicht(): GpPaketPreisSicht
    {
        return $this->preis;
    }

    /**
     * Status (§13.4), Lead-Schätzung, n_las_total (Plan, schreibt nichts).
     *
     * @param  array<string,array<string,mixed>>  $P
     */
    public function planeStatusUndLead(array &$P, GpPaketZaehler $z, array &$listen): void
    {
        foreach ($P as $key => &$g) {
            $wg = $g['wg'];
            $set = $g['set'];
            $paketStatus = (string) ($g['p']['status'] ?? 'tentative');
            $braucht = (bool) ($g['wert']['requires_la'] ?? true);
            $deriv = (bool) ($g['wert']['is_derivat'] ?? false);
            $platz = (bool) ($g['wert']['is_platzhalter'] ?? false);
            $g['status_soll'] = ($paketStatus === 'approved' && (! $braucht || $deriv || $platz || $set !== [])) ? 'approved' : 'tentative';
            if ($g['aktion'] === 'neu') {
                $z->inc($wg, 'gp_neu');
                $z->inc($wg, $g['status_soll'] === 'approved' ? 'gp_neu_approved' : 'gp_neu_tentative');
            }
            if ($g['derivat']) {
                $z->inc($wg, 'derivate');
            }
            if ($platz) {
                $z->inc($wg, 'platzhalter');
            }
            if ($set === []) {
                $z->inc($wg, 'gp_ohne_artikel');
                if ($braucht) {
                    $z->inc($wg, 'gp_ohne_artikel_requires_la');
                }
                $listen['ohne_artikel'][] = ['wg' => $wg, 'gp_key' => $key, 'name' => $g['name'], 'requires_la' => $braucht ? 1 : 0, 'status_neu' => $g['status_soll']];
            }
            if ($g['bestand'] !== null && $g['bestand']['status'] === 'tentative' && $set !== []) {
                $z->inc($wg, 'tentative_nun_mit_artikel');
                $listen['tentative_mit_artikel'][] = ['wg' => $wg, 'gp_key' => $key, 'name' => $g['name'], 'artikel' => count($set)];
            }
            $z->inc($wg, 'n_las_erwartet', count($set));

            // Lead (Schätzung): bestehender gültiger Lead bleibt, sonst Paket-Lead mit Preis, sonst gewählt (Obergrenze) oder Park
            if ($braucht && ! $deriv && $set !== []) {
                $alt = $g['lead_alt'];
                if ($alt !== null && in_array($alt, $set, true) && $this->preis->hatPreis($alt)) {
                    $z->inc($wg, 'lead_unveraendert');
                } elseif ($g['paket_lead'] !== null && in_array($g['paket_lead'], $set, true) && $this->preis->hatPreis($g['paket_lead'])) {
                    $z->inc($wg, 'lead_uebernommen');
                } elseif (array_filter($set, fn ($i) => $this->preis->hatPreis($i)) !== []) {
                    $z->inc($wg, 'lead_neu');
                } else {
                    $z->inc($wg, 'lead_park');
                    $listen['lead_park'][] = ['wg' => $wg, 'gp_key' => $key, 'name' => $g['name'], 'artikel' => count($set)];
                }
            }
        }
        unset($g);
    }

    // ── Verknüpfungen: Treffer über legacy_id, Reihenfolge, Guard-Simulation ──────────────────────

    /**
     * @param  array<string,array<string,mixed>>  $P
     */
    public function planeLinks(array &$P, array $paket, array $ctx, GpPaketZaehler $z, array &$listen, bool $umhaengen): void
    {
        // Artikel je GP, in fester Reihenfolge: Lead zuerst, dann nach legacy_id (Plan §0.7 — reproduzierbarer Guard)
        $je = [];
        $legacyZahl = [];
        foreach ($paket['artikel'] as $a) {
            $k = (string) ($a['gp_key'] ?? '');
            $legacy = $a['necta_legacy_id'] ?? null;
            if (! is_numeric($legacy)) {
                $z->inc($P[$k]['wg'] ?? '--', 'v_ohne_treffer');
                $listen['review_ohne_match'][] = ['wg' => $P[$k]['wg'] ?? '--', 'gp_key' => $k, 'gp_name' => $P[$k]['name'] ?? '', 'legacy_id' => (string) $legacy, 'grund' => 'necta_legacy_id fehlt oder ist keine Zahl', 'lead' => ''];

                continue;
            }
            $legacyZahl[(int) $legacy] = ($legacyZahl[(int) $legacy] ?? 0) + 1;
            if (isset($P[$k])) {
                $je[$k][] = $a;
            }
        }
        foreach ($je as &$liste) {
            usort($liste, fn ($x, $y) => [(int) !($x['lead'] ?? false), (int) $x['necta_legacy_id']] <=> [(int) !($y['lead'] ?? false), (int) $y['necta_legacy_id']]);
        }
        unset($liste);

        $legacies = [];
        foreach ($je as $liste) {
            foreach ($liste as $a) {
                $legacies[(int) $a['necta_legacy_id']] = true;
            }
        }
        $items = [];
        foreach (array_chunk(array_keys($legacies), 1000) as $chunk) {
            foreach (DB::table('foodalchemist_supplier_items')->whereIn('legacy_id', $chunk)->get(['id', 'legacy_id', 'team_id', 'deleted_at', 'designation', 'supplier_id']) as $r) {
                $items[(int) $r->legacy_id][] = $r;
            }
        }
        $itemIds = [];
        foreach ($items as $rows) {
            foreach ($rows as $r) {
                $itemIds[] = (int) $r->id;
            }
        }
        $structs = [];
        foreach (array_chunk($itemIds, 1000) as $chunk) {
            foreach (DB::table('foodalchemist_supplier_item_structures')->whereIn('supplier_item_id', $chunk)->get() as $r) {
                $structs[(int) $r->supplier_item_id] = (array) $r;
            }
        }
        // bestehende Verknüpfungen der bestehenden GPs (Ausgangsmenge für den Guard)
        $sets = [];
        $leadAlt = [];
        $ids = array_values(array_filter(array_map(fn ($g) => $g['id'], $P)));
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach (DB::table('foodalchemist_supplier_item_structures')->whereIn('gp_id', $chunk)->whereNull('deleted_at')->get(['gp_id', 'supplier_item_id']) as $r) {
                $sets[(int) $r->gp_id][] = (int) $r->supplier_item_id;
            }
        }
        $prof = $this->profile(array_merge($itemIds, array_merge(...array_values($sets ?: [[]]))));
        $lieferanten = DB::table('foodalchemist_suppliers')->pluck('name', 'id')->all();

        foreach ($P as $key => &$g) {
            $wg = $g['wg'];
            $set = $g['id'] !== null ? array_values(array_unique($sets[$g['id']] ?? [])) : [];
            $g['lead_alt'] = $g['bestand'] !== null && $g['bestand']['lead_la_supplier_item_id'] !== null ? (int) $g['bestand']['lead_la_supplier_item_id'] : null;
            $g['paket_lead'] = null;
            $g['links'] = [];
            foreach ($je[$key] ?? [] as $a) {
                $legacy = (int) $a['necta_legacy_id'];
                $l = ['legacy' => $legacy, 'status' => '', 'item_id' => null, 'struct' => null, 'gp_alt' => null, 'grund' => '', 'lead' => (bool) ($a['lead'] ?? false),
                    'struktur' => is_array($a['struktur'] ?? null) ? $a['struktur'] : [], 'abw' => []];
                $rows = $items[$legacy] ?? [];
                $aktiv = array_values(array_filter($rows, fn ($r) => $r->deleted_at === null && $r->team_id === null));
                $listeOhne = function (string $grund) use (&$l, &$listen, $z, $wg, $key, $g, $legacy) {
                    $l['status'] = 'ohne_match';
                    $l['grund'] = $grund;
                    $z->inc($wg, 'v_ohne_treffer');
                    $listen['review_ohne_match'][] = ['wg' => $wg, 'gp_key' => $key, 'gp_name' => $g['name'], 'legacy_id' => $legacy, 'grund' => $grund, 'lead' => $l['lead'] ? 1 : 0];
                };
                if (($legacyZahl[$legacy] ?? 0) > 1) {
                    $l['status'] = 'mehrdeutig';
                    $l['grund'] = 'Artikel mehrfach im Paket';
                    $z->inc($wg, 'v_mehrdeutig');
                    $listen['review_ohne_match'][] = ['wg' => $wg, 'gp_key' => $key, 'gp_name' => $g['name'], 'legacy_id' => $legacy, 'grund' => 'mehrdeutig: ' . $l['grund'], 'lead' => $l['lead'] ? 1 : 0];
                } elseif ($rows === []) {
                    $listeOhne('Artikel (legacy_id) gibt es auf io nicht');
                } elseif ($aktiv === []) {
                    $listeOhne(array_filter($rows, fn ($r) => $r->deleted_at === null) === [] ? 'Artikel auf io soft-gelöscht' : 'Artikel gehört einem Team (nicht global)');
                } elseif (count($aktiv) > 1) {
                    $l['status'] = 'mehrdeutig';
                    $l['grund'] = 'legacy_id auf io mehrfach (global)';
                    $z->inc($wg, 'v_mehrdeutig');
                    $listen['review_ohne_match'][] = ['wg' => $wg, 'gp_key' => $key, 'gp_name' => $g['name'], 'legacy_id' => $legacy, 'grund' => 'mehrdeutig: ' . $l['grund'], 'lead' => $l['lead'] ? 1 : 0];
                } else {
                    $item = $aktiv[0];
                    $iid = (int) $item->id;
                    $l['item_id'] = $iid;
                    $st = $structs[$iid] ?? null;
                    $l['struct'] = $st;
                    $anderes = $st !== null && $st['deleted_at'] === null && $st['gp_id'] !== null && ($g['id'] === null || (int) $st['gp_id'] !== $g['id']);
                    if ($st !== null && $st['deleted_at'] === null && $st['gp_id'] !== null && $g['id'] !== null && (int) $st['gp_id'] === $g['id']) {
                        $l['status'] = 'bestehend';
                        $z->inc($wg, 'v_bestehend');
                        if (! in_array($iid, $set, true)) {
                            $set[] = $iid;
                        }
                    } elseif ($anderes) {
                        $l['gp_alt'] = (int) $st['gp_id'];
                        $z->inc($wg, 'v_umhaengen_noetig');
                        if (! $umhaengen) {
                            $l['status'] = 'uebersprungen';
                            $l['grund'] = 'hängt an GP #' . $st['gp_id'] . ' (ohne --umhaengen übersprungen)';
                            $z->inc($wg, 'v_uebersprungen');
                        } else {
                            $this->simuliereGuard($l, $set, $prof, $g, $z, $listen, $item, $lieferanten, 'umhaengen');
                        }
                    } else {
                        $this->simuliereGuard($l, $set, $prof, $g, $z, $listen, $item, $lieferanten, 'neu');
                    }
                    if ($l['status'] === 'neu' || $l['status'] === 'umhaengen') {
                        $set[] = $iid;
                        if ($st === null) {
                            $z->inc($wg, 'struktur_neu');
                        }
                    }
                    if ($l['lead'] && $g['paket_lead'] === null && in_array($iid, $set, true)) {
                        $g['paket_lead'] = $iid;
                    }
                }
                $g['links'][] = $l;
            }
            $g['set'] = array_values(array_unique($set));
        }
        unset($g);
    }

    /** Spiegelt {@see LeadLaService::guardDeklarationsprofil()}: neuer Artikel gegen alle schon verknüpften. */
    private function simuliereGuard(array &$l, array $set, array $prof, array $g, GpPaketZaehler $z, array &$listen, object $item, array $lieferanten, string $erfolg): void
    {
        $neu = $prof[(int) $item->id] ?? [null, null];
        foreach ($set as $altId) {
            $alt = $prof[$altId] ?? [null, null];
            $abw = DeklarationsProfil::abweichungen($alt[0], $alt[1], $neu[0], $neu[1]);
            if ($abw !== []) {
                $l['status'] = 'guard_abgelehnt';
                $l['abw'] = $abw;
                $l['grund'] = implode('; ', $abw);
                $z->inc($g['wg'], 'v_guard_abgelehnt');
                $listen['guard_abgelehnt'][] = $this->guardZeile($g, $l, $item, $lieferanten, $altId);

                return;
            }
        }
        $l['status'] = $erfolg;
        $z->inc($g['wg'], $erfolg === 'neu' ? 'v_neu' : 'v_umgehaengt');
    }

    /** @return array<string,mixed> */
    private function guardZeile(array $g, array $l, object $item, array $lieferanten, int $gegenItem): array
    {
        return ['wg' => $g['wg'], 'gp_key' => $g['key'], 'gp_name' => $g['name'], 'legacy_id' => $l['legacy'], 'artikel_id' => (int) $item->id,
            'lieferant' => $lieferanten[$item->supplier_id] ?? '', 'artikel' => $item->designation, 'abweichend_von_artikel_id' => $gegenItem,
            'abweichungen' => implode('; ', $l['abw']),
            'vorschlag' => 'neues GP: „' . (trim((string) ($g['p']['main_ingredient_display'] ?? '')) ?: trim((string) strtok($g['name'], ':'))) . '“ + trennendes Merkmal (' . implode('; ', $l['abw']) . ') — nur Text, nichts angelegt'];
    }

    /**
     * Allergen-/Zusatzstoff-Profile je Artikel (wie `->with(['allergens','declarations'])` im Guard).
     *
     * @param  list<int>  $itemIds
     * @return array<int, array{0: ?FoodAlchemistItemAllergen, 1: ?FoodAlchemistItemDeclaration}>
     */
    private function profile(array $itemIds): array
    {
        $out = [];
        $itemIds = array_values(array_unique($itemIds));
        foreach (array_chunk($itemIds, 500) as $chunk) {
            $al = FoodAlchemistItemAllergen::query()->whereIn('supplier_item_id', $chunk)->get()->keyBy('supplier_item_id');
            $de = FoodAlchemistItemDeclaration::query()->whereIn('supplier_item_id', $chunk)->get()->keyBy('supplier_item_id');
            foreach ($chunk as $id) {
                $out[$id] = [$al->get($id), $de->get($id)];
            }
        }

        return $out;
    }

    /**
     * Verknüpfen — NORMALER Weg (LeadLaService::verknuepfen, Allergen-Guard), Massen-Events unterdrückt.
     *
     * @param  array<string,array<string,mixed>>  $P
     * @param  array<string,mixed>  $proto
     * @return array<int,bool> betroffene GP-IDs
     */
    public function verknuepfe(array &$P, Team $master, string $ts, array &$proto): array
    {
        $betroffen = [];
        $struktSpalten = array_flip(Schema::getColumnListing('foodalchemist_supplier_item_structures'));
        foreach ($P as $key => &$g) {
            $gpModel = null;
            $leadLaufAlt = $g['lead_alt'];
            foreach ($g['links'] as &$l) {
                if (! in_array($l['status'], ['neu', 'umhaengen'], true)) {
                    continue;
                }
                $gpModel ??= FoodAlchemistGp::visibleToTeam($master)->findOrFail($g['id']);
                $iid = (int) $l['item_id'];
                $st = $l['struct'];
                if ($l['status'] === 'umhaengen') {
                    $alt = (int) $l['gp_alt'];
                    DB::table('foodalchemist_supplier_item_structures')->where('id', $st['id'])->update(['gp_id' => null, 'updated_at' => $ts]);
                    $proto['entknuepft_direkt'][] = ['structure_id' => (int) $st['id'], 'supplier_item_id' => $iid, 'gp_alt' => $alt];
                    $betroffen[$alt] = true;
                    $altGp = DB::table('foodalchemist_gps')->where('id', $alt)->first();
                    if ($altGp !== null && (int) $altGp->lead_la_supplier_item_id === $iid) {
                        $m = FoodAlchemistGp::ohneTeamSicht('gp-paket-import: alter GP beim Umhängen')->find($alt);
                        $neuLead = $m !== null ? $this->lead->pickLeadLa($m, $master) : null;
                        DB::table('foodalchemist_gps')->where('id', $alt)->update(['lead_la_supplier_item_id' => $neuLead, 'updated_at' => $ts]);
                        $proto['lead'][] = ['gp_id' => $alt, 'alt' => $iid, 'neu' => $neuLead];
                    }
                }
                $structId = $st['id'] ?? null;
                $teamVorher = $st['team_id'] ?? null;
                if ($st === null) {
                    $structId = (int) DB::table('foodalchemist_supplier_item_structures')->insertGetId($this->strukturZeile($iid, $l['struktur'], $struktSpalten, $ts));
                    $proto['struktur_neu'][] = ['id' => $structId, 'supplier_item_id' => $iid];
                } elseif ($st['deleted_at'] !== null) {
                    $proto['struktur_reaktiviert'][] = ['id' => (int) $st['id'], 'gp_alt' => $st['gp_id'], 'deleted_at' => $st['deleted_at']];
                }
                try {
                    Model::withoutEvents(fn () => $this->lead->verknuepfen($master, $gpModel, $iid));
                } catch (\RuntimeException $e) {
                    $proto['guard_real_abgelehnt']++;
                    if ($st === null) {
                        // die Struktur-Zeile bleibt (ohne GP) — Struktur-Felder aus dem Paket schaden nicht; Rückweg entfernt sie
                    }
                    $l['status'] = str_starts_with($e->getMessage(), 'Allergene oder Zusatzstoffe') ? 'guard_abgelehnt' : 'fehler';
                    $l['grund'] = $e->getMessage();

                    continue;
                }
                $gpModel->refresh();
                if ($teamVorher === null) {                                    // verknuepfen() schreibt team_id ??= Master — globale Zeilen bleiben global
                    DB::table('foodalchemist_supplier_item_structures')->where('id', $structId)->whereNotNull('team_id')->update(['team_id' => null]);
                    $proto['struktur_team_null'][] = (int) $structId;
                }
                $proto['verknuepfungen'][] = ['structure_id' => (int) $structId, 'supplier_item_id' => $iid, 'gp_alt' => $l['status'] === 'umhaengen' ? (int) $l['gp_alt'] : null, 'gp_neu' => (int) $g['id']];
            }
            unset($l);
            $betroffen[(int) $g['id']] = true;
            $g['lead_laufstart'] = $leadLaufAlt;
        }
        unset($g);

        return $betroffen;
    }

    /**
     * n_las_total, Lead (§4.3), Status — gesammelt am Ende (nach dem Verknüpfen).
     *
     * @param  array<string,array<string,mixed>>  $P
     * @param  array<int,bool>  $betroffen
     * @param  array<int,string>  $byId
     * @param  array<string,mixed>  $proto
     * @param  list<array>  $leadPark
     * @param  list<int>  $leadGeaendert
     */
    public function schliesse(array $P, array $betroffen, array $byId, Team $master, string $ts, array &$proto, array &$leadPark, array &$leadGeaendert): void
    {
        $leadIst = [];
        $leadPark = [];                                  // Schätzung des Plans durch den Ist-Stand ersetzen
        foreach (array_keys($betroffen) as $gpId) {
            $gpRow = DB::table('foodalchemist_gps')->where('id', $gpId)->first();
            if ($gpRow === null) {
                continue;
            }
            $linked = DB::table('foodalchemist_supplier_item_structures')->where('gp_id', $gpId)->whereNull('deleted_at')->pluck('supplier_item_id')->map(fn ($v) => (int) $v)->all();
            $nAlt = (int) $gpRow->n_las_total;
            $proto['n_las'][] = ['gp_id' => $gpId, 'alt' => isset($byId[$gpId]) ? ($P[$byId[$gpId]]['aktion'] === 'neu' ? 0 : (int) ($P[$byId[$gpId]]['bestand']['n_las_total'] ?? 0)) : $nAlt, 'neu' => count($linked)];
            $upd = [];
            if ($nAlt !== count($linked)) {
                $upd['n_las_total'] = count($linked);
            }
            $entry = isset($byId[$gpId]) ? $P[$byId[$gpId]] : null;
            if ($entry !== null) {
                [$neuLead, $art] = $this->entscheideLead($entry, $linked, $master, $gpId);
                $leadAltVorLauf = $entry['lead_alt'];
                $aktuell = $gpRow->lead_la_supplier_item_id !== null ? (int) $gpRow->lead_la_supplier_item_id : null;
                if ($art !== null) {
                    $leadIst[$entry['wg']][$art] = ($leadIst[$entry['wg']][$art] ?? 0) + 1;
                    if ($art === 'lead_park') {
                        $leadPark[] = ['wg' => $entry['wg'], 'gp_key' => $entry['key'], 'name' => $entry['name'], 'artikel' => count($linked)];
                    }
                }
                if ($neuLead !== $aktuell) {
                    $upd['lead_la_supplier_item_id'] = $neuLead;
                }
                if ($neuLead !== $leadAltVorLauf) {
                    $proto['lead'][] = ['gp_id' => $gpId, 'alt' => $leadAltVorLauf, 'neu' => $neuLead];
                    if ($entry['aktion'] !== 'neu') {
                        $leadGeaendert[] = $gpId;
                    }
                }
                // Status: neue GPs, die per Regel approved sein sollten, aber real keinen Artikel haben → tentative
                if ($entry['aktion'] === 'neu' && $entry['status_soll'] === 'approved' && $gpRow->status === 'approved' && $linked === []
                    && (bool) ($entry['wert']['requires_la'] ?? true) && ! ($entry['wert']['is_derivat'] ?? false) && ! ($entry['wert']['is_platzhalter'] ?? false)) {
                    $upd['status'] = 'tentative';
                    $proto['status_korrektur'][] = ['gp_id' => $gpId, 'alt' => 'approved', 'neu' => 'tentative'];
                }
            }
            if ($upd !== []) {
                DB::table('foodalchemist_gps')->where('id', $gpId)->update($upd + ['updated_at' => $ts]);
            }
        }
        $proto['lead_ist'] = $leadIst;
    }

    /**
     * Lead nach §4.3 (Ist): bestehender gültiger Lead bleibt; sonst Paket-Lead mit Preis; sonst {@see LeadLaService::pickLeadLa()} —
     * aber nur, wenn der Gewählte einen Preis hat (Regel aus lead-la-repick); sonst NULL und „Park“. Lead aus Artikel ohne Preis wird nie gesetzt.
     *
     * @param  list<int>  $linked
     * @return array{0: ?int, 1: ?string} [Lead, Zähler-Art]
     */
    private function entscheideLead(array $g, array $linked, Team $master, int $gpId): array
    {
        $braucht = (bool) ($g['wert']['requires_la'] ?? true);
        if (! $braucht || ($g['wert']['is_derivat'] ?? false) || $linked === []) {
            return [null, null];
        }
        $alt = $g['lead_alt'];
        if ($alt !== null && in_array($alt, $linked, true) && $this->preis->hatPreis($alt)) {
            return [$alt, 'lead_unveraendert'];
        }
        if ($g['paket_lead'] !== null && in_array($g['paket_lead'], $linked, true) && $this->preis->hatPreis($g['paket_lead'])) {
            return [$g['paket_lead'], 'lead_uebernommen'];
        }
        $m = FoodAlchemistGp::ohneTeamSicht('gp-paket-import: Lead-Wahl')->find($gpId);
        $pick = $m !== null ? $this->lead->pickLeadLa($m, $master) : null;
        if ($pick !== null && $this->preis->hatPreis($pick)) {
            return [$pick, 'lead_neu'];
        }
        // pickLeadLa rechnet ohne Team und sieht für globale Stamm-Artikel keine Preise → Rangliste mit Master-Preissicht, erster bepreister Artikel
        $bepreist = $m !== null ? $this->lead->rangliste($m, $master, null, true)->first(fn ($la) => $la->hat_aktiven_preis && $this->preis->hatPreis((int) $la->id)) : null;
        if ($bepreist !== null) {
            return [(int) $bepreist->id, 'lead_neu'];
        }

        return [null, 'lead_park'];
    }

    /** @param array<string,mixed> $struktur */
    private function strukturZeile(int $itemId, array $struktur, array $spalten, string $ts): array
    {
        $row = ['uuid' => (string) UuidV7::generate(), 'team_id' => null, 'supplier_item_id' => $itemId, 'gp_id' => null, 'created_at' => $ts, 'updated_at' => $ts];
        foreach (GpPaketValidator::STRUKTUR_SPALTEN as $c) {
            if (! array_key_exists($c, $struktur) || ! isset($spalten[$c])) {
                continue;
            }
            $v = $struktur[$c];
            $row[$c] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : ($v === '' ? null : $v);
        }
        $row['needs_review'] = (int) ($row['needs_review'] ?? 0);

        return $row;
    }
}
