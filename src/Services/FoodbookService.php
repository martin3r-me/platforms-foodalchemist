<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbook;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookKapitel;

/**
 * M11-02 / Doc 15 §9.3 + D-8: Foodbook-Service — Mappe + Kapitel-BAUM + Blöcke.
 *
 * Preis-Modell: jeder Block liefert einen Per-Person-Preis (concept_ref = Concept-
 * €/Person [person-unabhängig], recipe_ref = vk_netto × Menge). Ein Kapitel summiert
 * rekursiv über Blöcke + Unterkapitel (`kapitelAggregat`). Der **Gesamtpreis** =
 * Σ Top-Kapitel × **Pax am Foodbook** (F-12, D-CON-5) — erst hier wird die
 * Gästezahl bindend, nicht am Concept.
 *
 * Scope-Härte: visibleToTeam in JEDER Query; Schreiben nur durchs Besitzer-Team.
 */
class FoodbookService
{
    public function __construct(private ConceptService $concepts)
    {
    }

    public function paginateBrowser(array $filters, Team $team, int $perPage = 100): LengthAwarePaginator
    {
        return FoodAlchemistFoodbook::visibleToTeam($team)
            ->withCount('kapitel')
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters) {
                $s = '%' . mb_strtolower($filters['search']) . '%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(bezeichnung) LIKE ?', [$s])
                    ->orWhereRaw('LOWER(COALESCE(kunde, \'\')) LIKE ?', [$s])
                    ->orWhereRaw('LOWER(COALESCE(code, \'\')) LIKE ?', [$s]));
            })
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('jahr')->orderBy('bezeichnung')
            ->paginate($perPage);
    }

    public function detail(Team $team, int $id): ?FoodAlchemistFoodbook
    {
        return FoodAlchemistFoodbook::visibleToTeam($team)
            ->with(['kapitel' => fn ($q) => $q->orderBy('position'),
                'kapitel.blocks' => fn ($q) => $q->orderBy('position'),
                'kapitel.blocks.concept:id,name,preis_pro_person_cache',
                'kapitel.blocks.gericht:id,name,vk_netto'])
            ->find($id);
    }

    // ── Foodbook ────────────────────────────────────────────────────────────

    private const FELDER = ['code', 'bezeichnung', 'jahr', 'kunde', 'personen', 'status', 'beschreibung', 'note'];

    public function create(Team $team, array $in): FoodAlchemistFoodbook
    {
        return FoodAlchemistFoodbook::create([
            'team_id' => $team->id,
            'bezeichnung' => trim((string) ($in['bezeichnung'] ?? 'Neues Foodbook')) ?: 'Neues Foodbook',
            'kunde' => $in['kunde'] ?? null,
            'jahr' => $in['jahr'] ?? null,
            'personen' => $in['personen'] ?? null,
            'status' => $in['status'] ?? 'draft',
        ]);
    }

    public function update(Team $team, int $id, array $in): FoodAlchemistFoodbook
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($id);
        $this->guard($fb, $team);
        $fb->update(array_intersect_key($in, array_flip(self::FELDER)));

        return $fb->refresh();
    }

    public function delete(Team $team, int $id): void
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($id);
        $this->guard($fb, $team);
        $fb->delete();
    }

    // ── Kapitel-Baum ──────────────────────────────────────────────────────────

    /** @return list<array{id:int, titel:string, parent_id:?int, depth:int}> Pre-Order */
    public function kapitelTree(Team $team, int $foodbookId): array
    {
        $alle = FoodAlchemistFoodbookKapitel::visibleToTeam($team)
            ->where('foodbook_id', $foodbookId)->orderBy('position')->get(['id', 'titel', 'parent_id']);
        $byParent = $alle->groupBy(fn ($k) => $k->parent_id ?? 0);
        $out = [];
        $walk = function ($parentId, int $depth) use (&$walk, $byParent, &$out) {
            foreach ($byParent[$parentId] ?? [] as $k) {
                $out[] = ['id' => (int) $k->id, 'titel' => $k->titel, 'parent_id' => $k->parent_id !== null ? (int) $k->parent_id : null, 'depth' => $depth];
                $walk((int) $k->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    public function addKapitel(Team $team, int $foodbookId, array $in = [], ?int $parentId = null): FoodAlchemistFoodbookKapitel
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);

        return FoodAlchemistFoodbookKapitel::create([
            'team_id' => $fb->team_id, 'foodbook_id' => $fb->id, 'parent_id' => $parentId ?: null,
            'titel' => trim((string) ($in['titel'] ?? 'Neues Kapitel')) ?: 'Neues Kapitel',
            'preis_modus' => $in['preis_modus'] ?? 'auto',
            'position' => (int) FoodAlchemistFoodbookKapitel::where('foodbook_id', $fb->id)
                ->when($parentId, fn ($q, $p) => $q->where('parent_id', $p), fn ($q) => $q->whereNull('parent_id'))
                ->max('position') + 1,
        ]);
    }

    private const KAPITEL_FELDER = ['titel', 'konsumententitel', 'claim', 'beschreibung', 'preis_pro_person', 'preis_modus'];

    public function updateKapitel(Team $team, int $id, array $in): FoodAlchemistFoodbookKapitel
    {
        $k = $this->ownedKapitel($team, $id);
        $k->update(array_intersect_key($in, array_flip(self::KAPITEL_FELDER)));

        return $k->refresh();
    }

    /** Verschieben mit Zyklus-Schutz (kein Knoten unter eigenen Nachfahren). */
    public function moveKapitel(Team $team, int $id, ?int $newParentId): void
    {
        $k = $this->ownedKapitel($team, $id);
        if ($newParentId !== null) {
            if ($newParentId === $id || in_array($newParentId, $this->descendantKapitelIds($team, $k->foodbook_id, $id), true)) {
                throw new \RuntimeException('Zyklus: Kapitel kann nicht unter einen eigenen Nachfahren.');
            }
        }
        $k->update(['parent_id' => $newParentId ?: null]);
    }

    /** @param list<int> $ids */
    public function reorderKapitel(Team $team, int $foodbookId, ?int $parentId, array $ids): void
    {
        $fb = FoodAlchemistFoodbook::visibleToTeam($team)->findOrFail($foodbookId);
        $this->guard($fb, $team);
        DB::transaction(function () use ($foodbookId, $ids) {
            foreach (array_values($ids) as $i => $id) {
                FoodAlchemistFoodbookKapitel::where('id', (int) $id)->where('foodbook_id', $foodbookId)->update(['position' => $i]);
            }
        });
    }

    public function deleteKapitel(Team $team, int $id): void
    {
        $this->ownedKapitel($team, $id)->delete();
    }

    private function descendantKapitelIds(Team $team, int $foodbookId, int $kapitelId): array
    {
        $kinder = [];
        foreach ($this->kapitelTree($team, $foodbookId) as $row) {
            $kinder[$row['parent_id'] ?? 0][] = $row['id'];
        }
        $ids = [];
        $stack = $kinder[$kapitelId] ?? [];
        while ($stack) {
            $id = array_pop($stack);
            $ids[] = $id;
            foreach ($kinder[$id] ?? [] as $kid) {
                $stack[] = $kid;
            }
        }

        return $ids;
    }

    // ── Blöcke ────────────────────────────────────────────────────────────────

    private const BLOCK_FELDER = ['type', 'ebene', 'sichtbar', 'bezeichnung', 'kundentext', 'interne_bemerkung',
        'variant_group_id', 'concept_id', 'vk_recipe_id', 'menge', 'einheit_vocab_id', 'preis_wert', 'preis_basis', 'hoehe'];

    public function addBlock(Team $team, int $kapitelId, array $in): FoodAlchemistFoodbookBlock
    {
        $k = $this->ownedKapitel($team, $kapitelId);
        $daten = array_intersect_key($in, array_flip(self::BLOCK_FELDER));
        $daten['type'] = in_array($in['type'] ?? '', ['concept_ref', 'recipe_ref', 'header', 'text', 'spacer', 'image'], true) ? $in['type'] : 'text';
        $daten['team_id'] = $k->team_id;
        $daten['position'] = (int) $k->blocks()->max('position') + 1;

        return $k->blocks()->create($daten);
    }

    public function updateBlock(Team $team, int $blockId, array $in): FoodAlchemistFoodbookBlock
    {
        $block = $this->ownedBlock($team, $blockId);
        $block->update(array_intersect_key($in, array_flip(self::BLOCK_FELDER)));

        return $block->refresh();
    }

    public function deleteBlock(Team $team, int $blockId): void
    {
        $this->ownedBlock($team, $blockId)->delete();
    }

    /** @param list<int> $ids */
    public function reorderBlocks(Team $team, int $kapitelId, array $ids): void
    {
        $this->ownedKapitel($team, $kapitelId);
        DB::transaction(function () use ($kapitelId, $ids) {
            foreach (array_values($ids) as $i => $id) {
                FoodAlchemistFoodbookBlock::where('id', (int) $id)->where('kapitel_id', $kapitelId)->update(['position' => $i]);
            }
        });
    }

    // ── Aggregat / Preis (M11 Cockpit) ──────────────────────────────────────────

    /**
     * Per-Person-Preis eines Blocks: concept_ref = Concept-€/Person (person-unabhängig),
     * recipe_ref = vk_netto × Menge, sonst 0.
     *
     * @return array{vk: float, ek: float}
     */
    public function blockPreis(FoodAlchemistFoodbookBlock $block): array
    {
        if ($block->type === 'concept_ref' && $block->concept) {
            $cockpit = $this->concepts->preisCockpit($block->concept);

            return ['vk' => (float) $cockpit['preis_pro_person'], 'ek' => (float) $cockpit['ek_pro_person']];
        }
        if ($block->type === 'recipe_ref' && $block->gericht) {
            $faktor = $block->menge !== null ? (float) $block->menge : 1.0;

            return ['vk' => round((float) ($block->gericht->vk_netto ?? 0) * $faktor, 2),
                'ek' => round((float) ($block->gericht->ek_total_eur ?? 0) * $faktor, 2)];
        }

        return ['vk' => 0.0, 'ek' => 0.0];
    }

    /**
     * Rekursives Kapitel-Aggregat (Per-Person): sichtbare Blöcke + Unterkapitel.
     * Manuell gesetzter `preis_pro_person` übersteuert die VK-Summe (EK bleibt gerechnet).
     *
     * @return array{vk_pro_person: float, ek_pro_person: float, wareneinsatz_prozent: ?float}
     */
    public function kapitelAggregat(Team $team, FoodAlchemistFoodbookKapitel $kapitel): array
    {
        $kapitel->loadMissing(['blocks' => fn ($q) => $q->where('sichtbar', true),
            'blocks.concept:id,name,preis_pro_person_cache', 'blocks.gericht:id,vk_netto,ek_total_eur',
            'children']);

        $vk = 0.0;
        $ek = 0.0;
        foreach ($kapitel->blocks as $block) {
            $p = $this->blockPreis($block);
            $vk += $p['vk'];
            $ek += $p['ek'];
        }
        foreach ($kapitel->children as $kind) {
            $kindAgg = $this->kapitelAggregat($team, $kind);
            $vk += $kindAgg['vk_pro_person'];
            $ek += $kindAgg['ek_pro_person'];
        }

        if ($kapitel->preis_modus === 'manuell' && $kapitel->preis_pro_person !== null) {
            $vk = (float) $kapitel->preis_pro_person;
        }

        return [
            'vk_pro_person' => round($vk, 2),
            'ek_pro_person' => round($ek, 2),
            'wareneinsatz_prozent' => $vk > 0 ? round($ek / $vk * 100, 1) : null,
        ];
    }

    /**
     * Foodbook-Gesamt: Σ Top-Kapitel (Per-Person) × Pax. Erst HIER wird die
     * Gästezahl bindend (F-12).
     *
     * @return array{vk_pro_person: float, ek_pro_person: float, personen: ?int, gesamt_vk: ?float, gesamt_ek: ?float}
     */
    public function gesamt(Team $team, FoodAlchemistFoodbook $fb): array
    {
        $vk = 0.0;
        $ek = 0.0;
        foreach ($fb->kapitel()->whereNull('parent_id')->get() as $top) {
            $agg = $this->kapitelAggregat($team, $top);
            $vk += $agg['vk_pro_person'];
            $ek += $agg['ek_pro_person'];
        }
        $pax = $fb->personen;

        return [
            'vk_pro_person' => round($vk, 2),
            'ek_pro_person' => round($ek, 2),
            'personen' => $pax,
            'gesamt_vk' => $pax !== null ? round($vk * $pax, 2) : null,
            'gesamt_ek' => $pax !== null ? round($ek * $pax, 2) : null,
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function ownedKapitel(Team $team, int $id): FoodAlchemistFoodbookKapitel
    {
        $k = FoodAlchemistFoodbookKapitel::visibleToTeam($team)->findOrFail($id);
        if (! $k->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Foodbook — Pflege nur durchs Besitzer-Team (D1).');
        }

        return $k;
    }

    private function ownedBlock(Team $team, int $id): FoodAlchemistFoodbookBlock
    {
        $block = FoodAlchemistFoodbookBlock::visibleToTeam($team)->findOrFail($id);
        if (! $block->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Foodbook — Pflege nur durchs Besitzer-Team (D1).');
        }

        return $block;
    }

    private function guard(FoodAlchemistFoodbook $fb, Team $team): void
    {
        if (! $fb->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbtes Foodbook — Pflege nur durchs Besitzer-Team (D1).');
        }
    }
}
