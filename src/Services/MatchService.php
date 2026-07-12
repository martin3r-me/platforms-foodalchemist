<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Enums\MatchBand;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplierItem;
use Platform\FoodAlchemist\Services\Matching\TokenEngine;

/**
 * M3-08: MatchService v1 — GP-Vorschläge für UNGEMAPPTE LAs (LA-Verknüpfen, M3-11-Bulk).
 *
 * Zwei Stufen:
 *  1. exact: identische EAN (packaging/ordering) bzw. supplier+article_number eines
 *     BEREITS GEMAPPTEN LAs ⇒ dessen GP mit Score 1.0 (sicherste Quelle — Dubletten).
 *  2. fuzzy: GL-04-Kern (TokenEngine) — F1 über Designation↔GP-Name + Slug-Pfade
 *     + Name-Containment-Floor; Bänder nach §4.1 (0.85/0.70/0.50), < 0.50 fliegt raus.
 *
 * Voller GL-04-Port (Pools, Aliasse, Tiebreaker, 96 Golden) folgt in M4-09.
 */
class MatchService
{
    public function __construct(private TokenEngine $engine)
    {
    }

    /**
     * @return Collection<int, array{gp: FoodAlchemistGp, score: float, band: MatchBand, methode: string}>
     */
    public function vorschlaegeFuerLa(FoodAlchemistSupplierItem $la, Team $team, int $limit = 5): Collection
    {
        $vorschlaege = $this->exactDubletten($la, $team);

        if ($vorschlaege->count() < $limit) {
            $gesehen = $vorschlaege->pluck('gp')->pluck('id')->all();
            foreach ($this->fuzzyByName($la, $team, $limit, $gesehen) as $treffer) {
                $vorschlaege->push($treffer);
            }
        }

        return $vorschlaege
            ->sortBy([fn ($a, $b) => $b['score'] <=> $a['score'], fn ($a, $b) => $a['gp']->id <=> $b['gp']->id])
            ->take($limit)
            ->values();
    }

    /**
     * M3-11: Bulk-Lauf je Lieferant — ungemappte, aktive LAs → beste Vorschläge
     * in die tentative Queue (foodalchemist_match_proposals). Fuzzy läuft über
     * einen invertierten Token-Index (1× aufgebaut), sonst wären es
     * |Items| × |GPs| Voll-Scans.
     *
     * @return array{geprueft: int, exact: int, fuzzy: int, ohne_treffer: int, uebersprungen: int}
     */
    public function bulkFuerLieferant(Team $team, int $supplierId): array
    {
        $items = FoodAlchemistSupplierItem::query()
            ->visibleToTeam($team)
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'foodalchemist_supplier_items.id')
            ->where('foodalchemist_supplier_items.supplier_id', $supplierId)
            ->whereNull('s.gp_id')
            ->whereNull('s.deleted_at')
            ->where('foodalchemist_supplier_items.is_discontinued', false)
            ->select('foodalchemist_supplier_items.*')
            ->orderBy('foodalchemist_supplier_items.id')
            ->get();

        $stats = ['geprueft' => 0, 'exact' => 0, 'fuzzy' => 0, 'ohne_treffer' => 0, 'uebersprungen' => 0];
        if ($items->isEmpty()) {
            return $stats;
        }

        // bereits entschiedene/offene Items überspringen (Queue bleibt idempotent)
        $bereitsInQueue = \Platform\FoodAlchemist\Models\FoodAlchemistMatchProposal::query()
            ->whereIn('supplier_item_id', $items->pluck('id'))
            ->whereIn('status', ['offen', 'akzeptiert'])
            ->pluck('supplier_item_id')->flip();

        // Exact-Prefetch: EAN→gp + (supplier, artno)→gp aus bereits gemappten LAs (2 Queries)
        [$eanZuGp, $artnoZuGp] = $this->exactBruecken($team, $supplierId);

        // Fuzzy: invertierter Token-Index über die sichtbaren GPs (1× pro Lauf)
        [$gpDaten, $tokenIndex] = $this->gpTokenIndex($team);

        foreach ($items as $item) {
            if (isset($bereitsInQueue[$item->id])) {
                $stats['uebersprungen']++;

                continue;
            }
            $stats['geprueft']++;

            $gpId = null;
            $methode = null;
            $score = 1.0;
            foreach ([$item->ean_packaging, $item->ean_ordering] as $ean) {
                if ($ean !== null && isset($eanZuGp[$ean])) {
                    [$gpId, $methode] = [$eanZuGp[$ean], 'exact_ean'];

                    break;
                }
            }
            if ($gpId === null && $item->article_number !== null && isset($artnoZuGp[$item->article_number])) {
                [$gpId, $methode] = [$artnoZuGp[$item->article_number], 'exact_artno'];
            }

            if ($gpId === null) {
                $query = $this->engine->tokenize((string) $item->designation);
                $kandidaten = [];
                foreach ($query as $token) {
                    // Stem-Schlüssel: sonst verfehlt der Index Stem-Matches (tomaten↔tomate)
                    foreach ($tokenIndex[$this->engine->stemGerman($token)] ?? [] as $kandidatId) {
                        $kandidaten[$kandidatId] = true;
                    }
                }
                $bester = null;
                foreach (array_keys($kandidaten) as $kandidatId) {
                    $gp = $gpDaten[$kandidatId];
                    $s = $this->engine->matchScore($query, null, $gp['tokens'], $gp['slug']);
                    if ($s < 0.90 && $this->engine->headMatchesQuery($gp['name'], $query)) {
                        $s = 0.90;
                    }
                    if ($s >= 0.50 && ($bester === null || $s > $bester['score']
                        || ($s === $bester['score'] && $kandidatId < $bester['gp_id']))) {
                        $bester = ['gp_id' => $kandidatId, 'score' => $s];
                    }
                }
                if ($bester !== null) {
                    [$gpId, $methode, $score] = [$bester['gp_id'], 'fuzzy_name', round($bester['score'], 4)];
                }
            }

            if ($gpId === null) {
                $stats['ohne_treffer']++;

                continue;
            }
            \Platform\FoodAlchemist\Models\FoodAlchemistMatchProposal::create([
                'team_id' => $team->id,
                'supplier_item_id' => $item->id,
                'gp_id' => $gpId,
                'score' => $score,
                'band' => MatchBand::fuerScore($score)->value,
                'methode' => $methode,
            ]);
            $stats[$methode === 'fuzzy_name' ? 'fuzzy' : 'exact']++;
        }

        return $stats;
    }

    /** Review-Entscheidung: Übernehmen mappt die Struktur + triggert Lead-Neuwahl (GL-03 T3). */
    public function uebernehmeVorschlag(Team $team, int $proposalId): void
    {
        $proposal = \Platform\FoodAlchemist\Models\FoodAlchemistMatchProposal::where('status', 'offen')
            ->where('team_id', $team->id)->findOrFail($proposalId);
        $gp = FoodAlchemistGp::visibleToTeam($team)->findOrFail($proposal->gp_id);

        app(LeadLaService::class)->verknuepfen($team, $gp, $proposal->supplier_item_id);
        $proposal->update(['status' => 'akzeptiert']);
    }

    public function verwerfeVorschlag(Team $team, int $proposalId): void
    {
        \Platform\FoodAlchemist\Models\FoodAlchemistMatchProposal::where('status', 'offen')
            ->where('team_id', $team->id)
            ->findOrFail($proposalId)->update(['status' => 'verworfen']);
    }

    /** @return array{0: array<string,int>, 1: array<string,int>} ean→gp_id, artno→gp_id (nur eindeutige) */
    private function exactBruecken(Team $team, int $supplierId): array
    {
        $gemappt = DB::table('foodalchemist_supplier_items AS i')
            ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'i.id')
            ->whereNotNull('s.gp_id')
            ->whereNull('i.deleted_at')->whereNull('s.deleted_at')
            ->select('i.supplier_id', 'i.ean_packaging', 'i.ean_ordering', 'i.article_number', 's.gp_id')
            ->get();

        $sichtbar = FoodAlchemistGp::visibleToTeam($team)->pluck('id')->flip();
        $eanZuGp = [];
        $artnoZuGp = [];
        foreach ($gemappt as $zeile) {
            if (! isset($sichtbar[$zeile->gp_id])) {
                continue;                                                    // D1: nur sichtbare Ziele
            }
            foreach ([$zeile->ean_packaging, $zeile->ean_ordering] as $ean) {
                if ($ean !== null && $ean !== '') {
                    // mehrdeutige EANs (verschiedene GPs) raus — nur sichere Brücken
                    $eanZuGp[$ean] = isset($eanZuGp[$ean]) && $eanZuGp[$ean] !== (int) $zeile->gp_id ? -1 : (int) $zeile->gp_id;
                }
            }
            if ((int) $zeile->supplier_id === $supplierId && $zeile->article_number !== null && $zeile->article_number !== '') {
                $artnoZuGp[$zeile->article_number] = isset($artnoZuGp[$zeile->article_number]) && $artnoZuGp[$zeile->article_number] !== (int) $zeile->gp_id ? -1 : (int) $zeile->gp_id;
            }
        }

        return [array_filter($eanZuGp, fn ($v) => $v > 0), array_filter($artnoZuGp, fn ($v) => $v > 0)];
    }

    /** @return array{0: array<int, array{name: string, tokens: array, slug: ?string}>, 1: array<string, array<int>>} */
    private function gpTokenIndex(Team $team): array
    {
        $gpDaten = [];
        $tokenIndex = [];
        FoodAlchemistGp::visibleToTeam($team)
            ->select('id', 'name', 'main_ingredient_slug')
            ->orderBy('id')
            ->chunk(2000, function ($gps) use (&$gpDaten, &$tokenIndex) {
                foreach ($gps as $gp) {
                    $tokens = $this->engine->tokenize($gp->name);
                    $gpDaten[$gp->id] = ['name' => $gp->name, 'tokens' => $tokens, 'slug' => $gp->main_ingredient_slug];
                    foreach ($tokens as $token) {
                        $tokenIndex[$this->engine->stemGerman($token)][] = $gp->id;  // Stem-Schlüssel
                    }
                }
            });

        return [$gpDaten, $tokenIndex];
    }

    // ── Stufe 1: exakte Dubletten-Brücke ────────────────────────────────

    private function exactDubletten(FoodAlchemistSupplierItem $la, Team $team): Collection
    {
        $out = collect();

        $kandidatenQuery = function () use ($la) {
            return DB::table('foodalchemist_supplier_items AS i')
                ->join('foodalchemist_supplier_item_structures AS s', 's.supplier_item_id', '=', 'i.id')
                ->where('i.id', '!=', $la->id)
                ->whereNotNull('s.gp_id')
                ->whereNull('i.deleted_at')->whereNull('s.deleted_at');
        };

        $eans = array_values(array_filter([$la->ean_packaging, $la->ean_ordering]));
        $gpIds = [];
        if ($eans !== []) {
            $gpIds['exact_ean'] = $kandidatenQuery()
                ->where(fn ($q) => $q->whereIn('i.ean_packaging', $eans)->orWhereIn('i.ean_ordering', $eans))
                ->distinct()->pluck('s.gp_id');
        }
        if ($la->article_number !== null && $la->article_number !== '') {
            $gpIds['exact_artno'] = $kandidatenQuery()
                ->where('i.supplier_id', $la->supplier_id)
                ->where('i.article_number', $la->article_number)
                ->distinct()->pluck('s.gp_id');
        }

        $gesehen = [];
        foreach ($gpIds as $methode => $ids) {
            foreach (FoodAlchemistGp::visibleToTeam($team)->whereIn('id', $ids)->get() as $gp) {
                if (isset($gesehen[$gp->id])) {
                    continue;
                }
                $gesehen[$gp->id] = true;
                $out->push(['gp' => $gp, 'score' => 1.0, 'band' => MatchBand::Exact, 'methode' => $methode]);
            }
        }

        return $out;
    }

    // ── Stufe 2: fuzzy GL-04-Kern ───────────────────────────────────────

    /** @param array<int> $ausschluss bereits exakt getroffene GP-Ids */
    private function fuzzyByName(FoodAlchemistSupplierItem $la, Team $team, int $limit, array $ausschluss = []): array
    {
        $query = $this->engine->tokenize((string) $la->designation);
        if ($query === []) {
            return [];
        }

        $treffer = [];
        FoodAlchemistGp::visibleToTeam($team)
            ->whereNotIn('id', $ausschluss)
            ->select('id', 'name', 'main_ingredient_slug', 'commodity_group_code', 'status', 'team_id')
            ->orderBy('id')                                            // Inv. 7: deterministische Iteration
            ->chunk(2000, function ($gps) use (&$treffer, $query) {
                foreach ($gps as $gp) {
                    $score = $this->engine->matchScore($query, null, $this->engine->tokenize($gp->name), $gp->main_ingredient_slug);
                    if ($score < 0.90 && $this->engine->headMatchesQuery($gp->name, $query)) {
                        $score = 0.90;                                 // NAME_CONTAINMENT_FLOOR (4.4o)
                    }
                    if ($score >= 0.50) {
                        $treffer[] = ['gp' => $gp, 'score' => round($score, 4), 'band' => MatchBand::fuerScore($score), 'methode' => 'fuzzy_name'];
                    }
                }
            });

        usort($treffer, fn ($a, $b) => [$b['score'], $a['gp']->id] <=> [$a['score'], $b['gp']->id]);

        return array_slice($treffer, 0, $limit);
    }
}
