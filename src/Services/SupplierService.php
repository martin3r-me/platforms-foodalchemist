<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistSupplier;

/**
 * M2-01 / D-2: Lieferanten-Stamm — Liste mit den P-7-Zählern
 * („n Artikel · m gemapped" = LA-First-Fortschritt auf einen Blick).
 */
class SupplierService
{
    /**
     * Lieferanten der Team-Kette mit item_count + mapped_count (eine GROUP-BY-Query je Zähler).
     */
    public function listWithCounts(Team $team, bool $includeInactive = false, string $search = ''): Collection
    {
        $itemCounts = DB::table('foodalchemist_supplier_items')
            ->whereNull('deleted_at')
            ->selectRaw('supplier_id, COUNT(*) AS n')
            ->groupBy('supplier_id')
            ->pluck('n', 'supplier_id');

        $mappedCounts = DB::table('foodalchemist_supplier_item_structures AS s')
            ->join('foodalchemist_supplier_items AS i', 'i.id', '=', 's.supplier_item_id')
            ->whereNull('s.deleted_at')
            ->whereNotNull('s.gp_id')
            ->selectRaw('i.supplier_id, COUNT(*) AS n')
            ->groupBy('i.supplier_id')
            ->pluck('n', 'supplier_id');

        return FoodAlchemistSupplier::visibleToTeam($team)
            ->when(! $includeInactive, fn ($q) => $q->where('is_inactive', false))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%' . $search . '%'))
            ->orderBy('name')
            ->get()
            ->each(function ($supplier) use ($itemCounts, $mappedCounts) {
                $supplier->setAttribute('item_count', (int) ($itemCounts[$supplier->id] ?? 0));
                $supplier->setAttribute('mapped_count', (int) ($mappedCounts[$supplier->id] ?? 0));
            });
    }

    // ── Anlage & Lebenszyklus (Dominique-Feedback 2026-06-11, D-2 §1) ───

    /** „+ Neuer Lieferant": gehört dem anlegenden Team (D1 — Kind-eigene möglich). */
    public function create(Team $team, array $input): FoodAlchemistSupplier
    {
        $name = trim($input['name'] ?? '');
        if ($name === '') {
            throw new \RuntimeException('Lieferanten-Name ist Pflicht.');
        }
        if (FoodAlchemistSupplier::visibleToTeam($team)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            throw new \RuntimeException("Lieferant [{$name}] existiert bereits in der Team-Kette."); // V-06
        }

        return FoodAlchemistSupplier::create([
            'team_id' => $team->id,
            'name' => $name,
            'city' => ($input['city'] ?? '') ?: null,
            'email_order' => ($input['email_order'] ?? '') ?: null,
            'homepage' => ($input['homepage'] ?? '') ?: null,
        ]);
    }

    /** Inaktiv = soft (Liste blendet aus), nur Besitzer-Team (D1). */
    public function setInactive(Team $team, int $id, bool $inactive): void
    {
        $supplier = FoodAlchemistSupplier::visibleToTeam($team)->findOrFail($id);
        if (! $supplier->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbter Katalog-Lieferant — nur das Besitzer-Team darf (de)aktivieren (D1).');
        }
        $supplier->update(['is_inactive' => $inactive]);
    }
}
