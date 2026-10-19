<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistTeamFavorit;
use Platform\FoodAlchemist\Support\FavoritenOverlay;
use Platform\FoodAlchemist\Support\TeamScope;

/**
 * 06·H2 — Kuratierung der Favoriten-GPs (kuratierter Haus-Standard, opt-in
 * KI-Baustein). Hybrid: Auto-Score schlägt vor (Rangliste), Mensch pinnt/excludet.
 * Alle Mutationen laufen hier (kein Roh-SQL für Schreibwege).
 *
 * 2026-07-20 (Dominique): Pool auf JEDEN approved GP geöffnet (früher nur
 * Convenience-getaggte, §4). Convenience bleibt ein GP-Tag — der Generator kann
 * die Favoriten optional darauf verengen (Favoriten ∩ tag_is_convenience).
 *
 * Auto-Score je GP (Spec §5): Verwendungshäufigkeit × Lieferanten-Priorität
 * (Lead-LA-Supplier in der Prioritätskette) × Lead-LA-Vollständigkeit.
 * Gewichte bewusst konservativ; am realen Bestand nachkalibrierbar.
 *
 * Spec 85 FAV-1 (Dominique 19.10.): Favoriten sind je TEAM (Overlay `foodalchemist_team_favoriten`, wirksam über FavoritenOverlay mit
 * Übernahme vom Hauptteam) — nicht mehr die globalen Spalten am GP. Schreiben: eigenes Team, Rolle Kuratieren, GP für das Team sichtbar;
 * kein Besitz am GP nötig. Ohne Team (Konsole ohne --team) gibt es keine Favoriten.
 */
class FavoriteGpService
{
    /** Gewichte des Auto-Scores (Spec §10: am Bestand kalibrieren). */
    public const W_USAGE = 2.0;      // je Rezept-Verwendung

    public const W_LEAD = 3.0;       // Lead-LA gesetzt (Vollständigkeit)

    public const W_PRICE = 2.0;      // Lead-LA löst auf einen Preis auf

    public const W_PRIORITY_MAX = 5.0; // Bonus, wenn Lead-Supplier ganz oben in der Prioritätskette

    public function __construct(private readonly TeamSettingsService $settings) {}

    /**
     * Auto-Score-Rangliste aller approved GPs, bereits gepinnte oben markiert.
     * Absteigend nach Score. `$search` (Name-LIKE) + `$pinnedOnly` filtern
     * server-seitig — gepinnte Favoriten sind IMMER dabei (sonst wären
     * niedrig gescorte Favoriten unauffindbar).
     *
     * @return Collection<int, array{gp_id:int, name:string, usage:int, has_lead_la:bool, has_price:bool, priority_pos:?int, score:float, is_favorite:bool, is_convenience:bool, favorite_rank:?int}>
     */
    public function suggest(?Team $team, int $limit = 300, ?string $search = null, bool $pinnedOnly = false): Collection
    {
        $prioritaeten = $team !== null ? $this->settings->leadLaPrioritaeten($team) : [];
        $prioPos = array_flip(array_values(array_map('intval', $prioritaeten))); // supplier_id => index

        $suche = $search !== null ? trim($search) : '';
        $fav = $team !== null ? FavoritenOverlay::fuer($team) : [];   // Spec 85 FAV-1: Favoriten des Teams
        $rows = $this->favoritesBaseQuery($team)
            ->when($suche !== '', fn ($q) => $q->where('g.name', 'like', '%' . $suche . '%'))
            ->when($pinnedOnly, fn ($q) => $q->whereIn('g.id', array_keys($fav) ?: [0]))
            ->select([
                'g.id', 'g.name', 'g.lead_la_supplier_item_id', 'g.tag_is_convenience',
            ])
            ->selectSub(
                DB::table('foodalchemist_recipe_ingredients')
                    ->whereColumn('gp_id', 'g.id')->whereNull('deleted_at')
                    ->selectRaw('COUNT(*)'),
                'usage',
            )
            ->selectSub(
                DB::table('foodalchemist_supplier_items AS li')
                    ->whereColumn('li.id', 'g.lead_la_supplier_item_id')->whereNull('li.deleted_at')
                    ->selectRaw('li.supplier_id'),
                'lead_supplier_id',
            )
            ->selectSub(
                // Spec 82 3a: „hat Preis" am globalen Lead = Listenpreis-Zeilen.
                app(PriceService::class)->nurListenpreis(DB::table('foodalchemist_prices AS p'), 'p')
                    ->whereColumn('p.supplier_item_id', 'g.lead_la_supplier_item_id')->whereNull('p.deleted_at')
                    ->selectRaw('COUNT(*)'),
                'price_rows',
            )
            ->get();

        $mapped = $rows->map(function ($r) use ($prioPos, $fav) {
            $usage = (int) $r->usage;
            $hasLead = $r->lead_la_supplier_item_id !== null;
            $hasPrice = ((int) $r->price_rows) > 0;
            $priorityPos = ($r->lead_supplier_id !== null && isset($prioPos[(int) $r->lead_supplier_id]))
                ? (int) $prioPos[(int) $r->lead_supplier_id]
                : null;

            $priorityBonus = $priorityPos !== null
                ? max(0.0, self::W_PRIORITY_MAX - $priorityPos)
                : 0.0;

            $score = $usage * self::W_USAGE
                + ($hasLead ? self::W_LEAD : 0.0)
                + ($hasPrice ? self::W_PRICE : 0.0)
                + $priorityBonus;

            return [
                'gp_id' => (int) $r->id,
                'name' => (string) $r->name,
                'usage' => $usage,
                'has_lead_la' => $hasLead,
                'has_price' => $hasPrice,
                'priority_pos' => $priorityPos,
                'score' => round($score, 2),
                'is_favorite' => isset($fav[(int) $r->id]),
                'is_convenience' => (bool) $r->tag_is_convenience,
                'favorite_rank' => $fav[(int) $r->id]['rang'] ?? null,
            ];
        });

        // Score-Cap (der Pool ist der ganze approved-Bestand → Rangliste, nicht alles),
        // aber gepinnte Favoriten IMMER behalten (sonst fällt ein niedrig gescorter
        // Favorit aus der Liste und wäre nicht mehr entfernbar).
        $pinned = $mapped->where('is_favorite', true)->sortByDesc('score');
        $rest = $mapped->where('is_favorite', false)->sortByDesc('score')
            ->take(max(0, $limit - $pinned->count()));

        return $pinned->concat($rest)->sortByDesc('score')->values();
    }

    /**
     * Spec 19 E9.3: Verfügbarkeits-Bucket je GP für die Kreativ-Erdung.
     *   - fuehren = kuratierter Haus-Standard (is_favorite)
     *   - leicht  = beschaffbar JETZT (Lead-LA gesetzt UND auf Preis auflösend)
     *   - luecke  = keine beschaffbare Quelle (kein Lead-LA oder kein Preis)
     * Reine Read-Sicht, spiegelt die suggest()-Subqueries; team-sichtbar, ohne approved-Filter
     * (Verfügbarkeit ≠ Datenqualität — auch ein tentativer GP mit bepreistem LA ist beschaffbar).
     *
     * @param  list<int>  $gpIds
     * @return array<int, array{bucket: string, has_lead_la: bool, has_price: bool, priority_pos: ?int, is_favorite: bool}>
     */
    public function verfuegbarkeit(?Team $team, array $gpIds): array
    {
        $gpIds = array_values(array_unique(array_map('intval', $gpIds)));
        if ($gpIds === []) {
            return [];
        }
        $prioritaeten = $team !== null ? $this->settings->leadLaPrioritaeten($team) : [];
        $prioPos = array_flip(array_values(array_map('intval', $prioritaeten))); // supplier_id => index

        $q = DB::table('foodalchemist_gps AS g')->whereNull('g.deleted_at')->whereIn('g.id', $gpIds);
        if ($team !== null) {
            TeamScope::applyVisible($q, 'g.team_id', $team); // NULL (global) ∪ Ancestry — wie favoritesBaseQuery
        }
        $fav = $team !== null ? FavoritenOverlay::fuer($team) : [];   // Spec 85 FAV-1
        $rows = $q
            ->select(['g.id', 'g.lead_la_supplier_item_id'])
            ->selectSub(
                DB::table('foodalchemist_supplier_items AS li')
                    ->whereColumn('li.id', 'g.lead_la_supplier_item_id')->whereNull('li.deleted_at')
                    ->selectRaw('li.supplier_id'),
                'lead_supplier_id',
            )
            ->selectSub(
                // Spec 82 3a: „hat Preis" am globalen Lead = Listenpreis-Zeilen.
                app(PriceService::class)->nurListenpreis(DB::table('foodalchemist_prices AS p'), 'p')
                    ->whereColumn('p.supplier_item_id', 'g.lead_la_supplier_item_id')->whereNull('p.deleted_at')
                    ->selectRaw('COUNT(*)'),
                'price_rows',
            )
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $isFav = isset($fav[(int) $r->id]);
            $hasLead = $r->lead_la_supplier_item_id !== null;
            $hasPrice = ((int) $r->price_rows) > 0;
            $priorityPos = ($r->lead_supplier_id !== null && isset($prioPos[(int) $r->lead_supplier_id]))
                ? (int) $prioPos[(int) $r->lead_supplier_id] : null;

            $bucket = $isFav ? 'fuehren' : (($hasLead && $hasPrice) ? 'leicht' : 'luecke');
            $out[(int) $r->id] = [
                'bucket' => $bucket, 'has_lead_la' => $hasLead, 'has_price' => $hasPrice,
                'priority_pos' => $priorityPos, 'is_favorite' => $isFav,
            ];
        }

        return $out;
    }

    /** Wirksame Favoriten des Teams (nach Rang), nur für das Team sichtbare GPs. `favorite_rank` = Rang im Team. */
    public function current(?Team $team): Collection
    {
        if ($team === null) {
            return collect();
        }
        $fav = FavoritenOverlay::fuer($team);
        $gps = FoodAlchemistGp::visibleToTeam($team)->whereIn('id', array_keys($fav) ?: [0])
            ->get(['id', 'name', 'tag_is_convenience'])->keyBy('id');

        return collect(array_keys($fav))->map(fn ($id) => $gps->get($id)?->setAttribute('favorite_rank', $fav[$id]['rang']))
            ->filter()->values();
    }

    /**
     * GP als Favorit pinnen (idempotent). JEDER approved GP ist pinbar
     * (§4-Convenience-Zwang 2026-07-20 fallengelassen — Dominique).
     */
    public function pin(Team $team, FoodAlchemistGp $gp, ?int $rank = null): void
    {
        $this->pruefeSchreiben($team, $gp);
        FoodAlchemistTeamFavorit::updateOrCreate(['team_id' => $team->id, 'gp_id' => $gp->id], ['rang' => $rank, 'ausgeschlossen' => false]);
    }

    /**
     * GP aus den Favoriten des Teams nehmen. Eigener Eintrag weg; ist er danach noch Favorit (vom Hauptteam übernommen), wird er
     * für dieses Team ausgeschlossen.
     */
    public function exclude(Team $team, FoodAlchemistGp $gp): void
    {
        $this->pruefeSchreiben($team, $gp);
        FoodAlchemistTeamFavorit::where('team_id', $team->id)->where('gp_id', $gp->id)->delete();
        if (isset(FavoritenOverlay::fuer($team)[(int) $gp->id])) {
            FoodAlchemistTeamFavorit::create(['team_id' => $team->id, 'gp_id' => $gp->id, 'rang' => null, 'ausgeschlossen' => true]);
        }
    }

    /** Eigenen Eintrag (Favorit oder Ausschluss) entfernen — danach gilt wieder die Übernahme vom Hauptteam. */
    public function entfernen(Team $team, FoodAlchemistGp $gp): void
    {
        $this->pruefeSchreiben($team, $gp);
        FoodAlchemistTeamFavorit::where('team_id', $team->id)->where('gp_id', $gp->id)->delete();
    }

    /**
     * Flache Reihenfolge setzen: [gp_id => rank, …]. Nur bereits gepinnte GPs.
     *
     * @param  array<int,int>  $gpIdToRank
     */
    public function reorder(Team $team, array $gpIdToRank): int
    {
        app(FaRechte::class)->pruefeAngemeldet($team, \Platform\FoodAlchemist\Enums\FaRolle::Kuratieren, 'Favoriten pflegen');
        $n = 0;
        DB::transaction(function () use ($team, $gpIdToRank, &$n) {
            foreach ($gpIdToRank as $gpId => $rank) {
                $n += FoodAlchemistTeamFavorit::where('team_id', $team->id)->where('gp_id', (int) $gpId)->where('ausgeschlossen', false)
                    ->update(['rang' => (int) $rank, 'updated_at' => now()]);
            }
        });

        return $n;
    }

    /** Spec 85 FAV-1: eigene Liste pflegt, wer im Team kuratieren darf — für jeden GP, den das Team sieht (kein Besitz am GP nötig). */
    private function pruefeSchreiben(Team $team, FoodAlchemistGp $gp): void
    {
        app(FaRechte::class)->pruefeAngemeldet($team, \Platform\FoodAlchemist\Enums\FaRolle::Kuratieren, 'Favoriten pflegen');
        if (! FoodAlchemistGp::visibleToTeam($team)->whereKey($gp->id)->exists()) {
            throw new \RuntimeException('Dieses Grundprodukt sieht euer Team nicht.');
        }
    }

    /** Basis-Query: approved, echte (kein Platzhalter) GPs, team-sichtbar, Alias g. */
    private function favoritesBaseQuery(?Team $team)
    {
        $q = DB::table('foodalchemist_gps AS g')
            ->where('g.status', 'approved')
            ->where('g.is_platzhalter', false)
            ->whereNull('g.deleted_at');

        if ($team !== null) {
            TeamScope::applyVisible($q, 'g.team_id', $team); // NULL (global) ∪ Ancestry
        }

        return $q;
    }

}
