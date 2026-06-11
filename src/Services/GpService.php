<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistGp;
use Platform\FoodAlchemist\Models\FoodAlchemistLookupWarengruppe;

/**
 * Stateless Lese-/Such-Service für Grundprodukte (D-3-Teilmenge des Vertical Slice).
 * Schreibwege (Naming-Validierung GL-12, Merge, Lebenszyklus GL-07) folgen in der D-3-Ausbaustufe.
 */
class GpService
{
    /** D1 Eltern→Kinder: sichtbar = Team-Kette; ohne Team nichts (Leak-Schutz). */
    private function scoped(Builder $query, ?Team $team): Builder
    {
        return $team ? $query->visibleToTeam($team) : $query->whereRaw('1 = 0');
    }

    /**
     * GP-Liste mit Suche + Filtern, hierarchie-gescoped (D1).
     *
     * @param array{search?: string, warengruppe?: string, status?: string} $filters
     */
    public function paginate(array $filters, ?Team $team, int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->scoped(FoodAlchemistGp::query(), $team)
            ->with('warengruppe');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            // LOWER()-Vergleich statt LIKE-Collation-Annahmen (02_DATENMODELL Typ-Port / NOCASE-Ersatz)
            $needle = mb_strtolower($search);
            $query->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$needle}%"])
                    ->orWhereRaw('LOWER(hauptzutat_slug) LIKE ?', ["%{$needle}%"]);
            });
        }

        if (! empty($filters['warengruppe'])) {
            $query->where('warengruppe_code', $filters['warengruppe']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query
            ->orderBy('name')
            ->orderBy('id') // Determinismus-Anker (GL-04-Lehre: ORDER BY id als Tiebreaker)
            ->paginate($perPage);
    }

    public function find(int $id, ?Team $team): ?FoodAlchemistGp
    {
        return $this->scoped(FoodAlchemistGp::query(), $team)
            ->with(['warengruppe', 'preferredCountUnit', 'derivatVon', 'mergedInto', 'leadLa.supplier'])
            ->find($id);
    }

    /**
     * LA-Liste eines GP fürs Detail (GL-05-Sicht): Structure + Artikel + Lieferant + aktiver Preis.
     * Aktiver Preis = neueste nicht-blockierte Zeile (valid_to DESC, id DESC) — GL-11-Soll
     * „einheitlich aktiver_preis()" (A-2-Vereinheitlichung); Kategorisierung T1 folgt in D-2.
     */
    public function lasForGp(FoodAlchemistGp $gp): \Illuminate\Support\Collection
    {
        return $gp->structures()
            ->with(['item.supplier'])
            ->get()
            ->map(function ($structure) {
                $item = $structure->item;
                $price = $item?->prices()
                    ->where('is_blocked', false)
                    ->orderByDesc('valid_to')
                    ->orderByDesc('id')
                    ->first();

                return (object) [
                    'structure' => $structure,
                    'item' => $item,
                    'supplier' => $item?->supplier,
                    'price' => $price,
                ];
            })
            ->sortBy(fn ($la) => $la->supplier?->name ?? '~')
            ->values();
    }

    /** Warengruppen-Optionen für Filter-Dropdowns (code => "code name"). */
    public function warengruppenOptions(?Team $team): Collection
    {
        return $this->scoped(FoodAlchemistLookupWarengruppe::query(), $team)
            ->orderBy('code')
            ->get(['code', 'name']);
    }

    /** Status-Zähler für Dashboard-Tiles / Filter-Badges. */
    public function statusCounts(?Team $team): array
    {
        return $this->scoped(FoodAlchemistGp::query(), $team)
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status')
            ->all();
    }
}
