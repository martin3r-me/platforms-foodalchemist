<?php

namespace Platform\FoodAlchemist\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipeDarreichung;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarte;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekartePosition;
use Platform\FoodAlchemist\Models\FoodAlchemistSpeisekarteRubrik;

/**
 * Speisekarte-Service — Karte + Rubrik-BAUM + Positionen. Dritte Ausgabeform neben
 * Foodbook (Catering) und Speiseplan (GV): die Gastronomie-à-la-carte-Karte.
 *
 * Preis-Modell (Gastro): jede Position trägt einen FLACHEN VK (€/Position, kein ×Pax).
 *  - gericht_ref  → VK aus der Darreichung (Glas/Flasche/Portion) bzw. Standard-Darreichung
 *                   des Rezepts (DarreichungResolver), Legacy-Fallback recipes.sales_net.
 *  - menue_ref    → Concept-€/Person (Fix-Menü / Mehrgänger) über ConceptService::preisCockpit.
 *  - price_mode='manuell' übersteuert mit price_value.
 *
 * Scope-Härte: visibleToTeam in JEDER Query; Schreiben nur durchs Besitzer-Team (D1).
 */
class SpeisekarteService
{
    public function __construct(
        private ConceptService $concepts,
        private DarreichungResolver $darreichung,
    ) {
    }

    // ── Karte ────────────────────────────────────────────────────────────────

    private const FELDER = [
        'code', 'name', 'status', 'outlet_id', 'karten_typ', 'gueltig_von', 'gueltig_bis',
        'preis_anzeige_brutto', 'description', 'note', 'kundentyp', 'default_niveau',
        'default_convenience', 'writing_style_id',
    ];

    public function paginateBrowser(array $filters, Team $team, int $perPage = 100): LengthAwarePaginator
    {
        return FoodAlchemistSpeisekarte::visibleToTeam($team)
            ->withCount('sections')
            ->when(($filters['search'] ?? '') !== '', function ($q) use ($filters) {
                $s = '%' . mb_strtolower($filters['search']) . '%';
                $q->where(fn ($w) => $w
                    ->whereRaw('LOWER(name) LIKE ?', [$s])
                    ->orWhereRaw('LOWER(COALESCE(code, \'\')) LIKE ?', [$s]));
            })
            ->when(($filters['status'] ?? '') !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['karten_typ'] ?? '') !== '', fn ($q) => $q->where('karten_typ', $filters['karten_typ']))
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function detail(Team $team, int $id): ?FoodAlchemistSpeisekarte
    {
        return FoodAlchemistSpeisekarte::visibleToTeam($team)
            ->with([
                'sections' => fn ($q) => $q->orderBy('position'),
                'sections.items' => fn ($q) => $q->orderBy('position'),
                'sections.items.dish:id,name,sales_net,ek_total_eur',
                'sections.items.concept:id,name,price_per_person_cache',
                'outlet',
            ])
            ->find($id);
    }

    public function create(Team $team, array $in): FoodAlchemistSpeisekarte
    {
        return FoodAlchemistSpeisekarte::create([
            'team_id' => $team->id,
            'name' => trim((string) ($in['name'] ?? 'Neue Speisekarte')) ?: 'Neue Speisekarte',
            'status' => $in['status'] ?? 'entwurf',
            'karten_typ' => in_array($in['karten_typ'] ?? '', FoodAlchemistSpeisekarte::KARTEN_TYPEN, true) ? $in['karten_typ'] : 'alacarte',
            'outlet_id' => $in['outlet_id'] ?? null,
            'preis_anzeige_brutto' => $in['preis_anzeige_brutto'] ?? true,
            'description' => $in['description'] ?? null,
        ]);
    }

    public function update(Team $team, int $id, array $in): FoodAlchemistSpeisekarte
    {
        $karte = FoodAlchemistSpeisekarte::visibleToTeam($team)->findOrFail($id);
        $this->guard($karte, $team);
        $karte->update(array_intersect_key($in, array_flip(self::FELDER)));

        return $karte->refresh();
    }

    public function delete(Team $team, int $id): void
    {
        $karte = FoodAlchemistSpeisekarte::visibleToTeam($team)->findOrFail($id);
        $this->guard($karte, $team);
        $karte->delete();
    }

    // ── Rubrik-Baum ────────────────────────────────────────────────────────────

    /** @return list<array{id:int, title:string, parent_id:?int, art:string, depth:int}> Pre-Order */
    public function rubrikTree(Team $team, int $karteId): array
    {
        $alle = FoodAlchemistSpeisekarteRubrik::visibleToTeam($team)
            ->where('menu_card_id', $karteId)->orderBy('position')->get(['id', 'title', 'parent_id', 'art']);
        $byParent = $alle->groupBy(fn ($r) => $r->parent_id ?? 0);
        $out = [];
        $walk = function ($parentId, int $depth) use (&$walk, $byParent, &$out) {
            foreach ($byParent[$parentId] ?? [] as $r) {
                $out[] = ['id' => (int) $r->id, 'title' => $r->title, 'parent_id' => $r->parent_id !== null ? (int) $r->parent_id : null, 'art' => $r->art, 'depth' => $depth];
                $walk((int) $r->id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    public function addRubrik(Team $team, int $karteId, array $in = [], ?int $parentId = null): FoodAlchemistSpeisekarteRubrik
    {
        $karte = FoodAlchemistSpeisekarte::visibleToTeam($team)->findOrFail($karteId);
        $this->guard($karte, $team);
        if ($parentId !== null && ! FoodAlchemistSpeisekarteRubrik::where('menu_card_id', $karte->id)->whereKey($parentId)->exists()) {
            throw new \RuntimeException('parent_id gehört nicht zu dieser Speisekarte.');
        }

        return FoodAlchemistSpeisekarteRubrik::create([
            'team_id' => $karte->team_id,
            'menu_card_id' => $karte->id,
            'parent_id' => $parentId ?: null,
            'title' => trim((string) ($in['title'] ?? 'Neue Rubrik')) ?: 'Neue Rubrik',
            'consumer_title' => $in['consumer_title'] ?? null,
            'art' => in_array($in['art'] ?? '', FoodAlchemistSpeisekarteRubrik::ARTEN, true) ? $in['art'] : 'speisen',
            'position' => (int) FoodAlchemistSpeisekarteRubrik::where('menu_card_id', $karte->id)
                ->when($parentId, fn ($q, $p) => $q->where('parent_id', $p), fn ($q) => $q->whereNull('parent_id'))
                ->max('position') + 1,
        ]);
    }

    private const RUBRIK_FELDER = ['title', 'consumer_title', 'claim', 'description', 'art', 'preis_anzeige', 'status'];

    public function updateRubrik(Team $team, int $id, array $in): FoodAlchemistSpeisekarteRubrik
    {
        $rubrik = $this->ownedRubrik($team, $id);
        $rubrik->update(array_intersect_key($in, array_flip(self::RUBRIK_FELDER)));

        return $rubrik->refresh();
    }

    public function moveRubrik(Team $team, int $id, ?int $newParentId): void
    {
        $rubrik = $this->ownedRubrik($team, $id);
        if ($newParentId !== null) {
            $ziel = $this->ownedRubrik($team, $newParentId);
            if ($ziel->menu_card_id !== $rubrik->menu_card_id) {
                throw new \RuntimeException('Ziel-Rubrik gehört zu einer anderen Karte.');
            }
            // Zyklus-Schutz: Ziel darf kein Nachfahre der bewegten Rubrik sein.
            if ($this->istNachfahre($rubrik->menu_card_id, (int) $rubrik->id, $newParentId)) {
                throw new \RuntimeException('Verschieben würde einen Zyklus erzeugen.');
            }
        }
        $rubrik->update(['parent_id' => $newParentId ?: null]);
    }

    /** @param list<int> $ids */
    public function reorderRubriken(Team $team, int $karteId, ?int $parentId, array $ids): void
    {
        $karte = FoodAlchemistSpeisekarte::visibleToTeam($team)->findOrFail($karteId);
        $this->guard($karte, $team);
        DB::transaction(function () use ($karteId, $ids) {
            foreach (array_values($ids) as $i => $id) {
                FoodAlchemistSpeisekarteRubrik::where('id', (int) $id)->where('menu_card_id', $karteId)->update(['position' => $i]);
            }
        });
    }

    public function deleteRubrik(Team $team, int $id): void
    {
        $this->ownedRubrik($team, $id)->delete();
    }

    // ── Positionen ──────────────────────────────────────────────────────────────

    private const POSITION_FELDER = [
        'type', 'level', 'visible', 'label', 'consumer_text', 'interne_bemerkung', 'variant_group_id',
        'sales_recipe_id', 'concept_id', 'presentation_id', 'wording', 'price_mode', 'price_value',
        'height', 'payload_json',
    ];

    public function addPosition(Team $team, int $rubrikId, array $in): FoodAlchemistSpeisekartePosition
    {
        $rubrik = $this->ownedRubrik($team, $rubrikId);
        $daten = array_intersect_key($in, array_flip(self::POSITION_FELDER));
        $daten['type'] = in_array($in['type'] ?? '', FoodAlchemistSpeisekartePosition::TYPES, true) ? $in['type'] : 'text';
        if ($daten['type'] === 'gericht_ref') {
            $this->pruefeGerichtRef($team, $daten['sales_recipe_id'] ?? null);
        }
        if ($daten['type'] === 'menue_ref') {
            $this->pruefeMenueRef($team, $daten['concept_id'] ?? null);
        }
        $daten['team_id'] = $rubrik->team_id;
        $daten['position'] = (int) $rubrik->items()->max('position') + 1;

        return $rubrik->items()->create($daten);
    }

    public function updatePosition(Team $team, int $positionId, array $in): FoodAlchemistSpeisekartePosition
    {
        $pos = $this->ownedPosition($team, $positionId);
        $daten = array_intersect_key($in, array_flip(self::POSITION_FELDER));
        $effTyp = array_key_exists('type', $daten) ? $daten['type'] : $pos->type;
        if ($effTyp === 'gericht_ref' && array_key_exists('sales_recipe_id', $daten)) {
            $this->pruefeGerichtRef($team, $daten['sales_recipe_id']);
        }
        if ($effTyp === 'menue_ref' && array_key_exists('concept_id', $daten)) {
            $this->pruefeMenueRef($team, $daten['concept_id']);
        }
        $pos->update($daten);

        return $pos->refresh();
    }

    /**
     * gericht_ref-Guard: das referenzierte Gericht/Getränk muss dem Team sichtbar sein,
     * ein echtes VK-Gericht (`verkauf()`) und keine konzept-lokale Slot-Variante.
     */
    private function pruefeGerichtRef(Team $team, ?int $salesRecipeId): void
    {
        if ($salesRecipeId === null) {
            throw new \RuntimeException('gericht_ref-Position braucht ein sales_recipe_id (VK-Gericht).');
        }
        $ok = FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNull('variant_source_recipe_id')
            ->whereKey($salesRecipeId)->exists();
        if (! $ok) {
            throw new \RuntimeException("sales_recipe_id {$salesRecipeId} ist kein gültiges, sichtbares VK-Gericht.");
        }
    }

    /** menue_ref-Guard: das referenzierte Concept muss dem Team sichtbar + ein echtes Concept sein. */
    private function pruefeMenueRef(Team $team, ?int $conceptId): void
    {
        if ($conceptId === null) {
            throw new \RuntimeException('menue_ref-Position braucht ein concept_id (Fix-Menü).');
        }
        $ok = FoodAlchemistConcept::visibleToTeam($team)->echte()->whereKey($conceptId)->exists();
        if (! $ok) {
            throw new \RuntimeException("concept_id {$conceptId} ist kein gültiges, sichtbares Concept.");
        }
    }

    public function deletePosition(Team $team, int $positionId): void
    {
        $this->ownedPosition($team, $positionId)->delete();
    }

    /** @param list<int> $ids */
    public function reorderPositionen(Team $team, int $rubrikId, array $ids): void
    {
        $this->ownedRubrik($team, $rubrikId);
        DB::transaction(function () use ($rubrikId, $ids) {
            foreach (array_values($ids) as $i => $id) {
                FoodAlchemistSpeisekartePosition::where('id', (int) $id)->where('section_id', $rubrikId)->update(['position' => $i]);
            }
        });
    }

    /** Wahl-Gruppe „A|B|C": nächste freie Gruppen-ID in der Rubrik. */
    public function nextVariantGroupId(Team $team, int $rubrikId): int
    {
        $this->ownedRubrik($team, $rubrikId);

        return (int) FoodAlchemistSpeisekartePosition::where('section_id', $rubrikId)->max('variant_group_id') + 1;
    }

    // ── Preis ────────────────────────────────────────────────────────────────

    /**
     * Netto-VK einer Position (flach, €/Position). Manuell übersteuert; sonst je Typ
     * aus Darreichung (Gericht/Getränk) bzw. Concept-€/Person (Fix-Menü).
     *
     * @return array{vk: ?float, quelle: string} quelle ∈ manuell|darreichung|legacy|concept|keine
     */
    public function positionPreis(FoodAlchemistSpeisekartePosition $pos): array
    {
        if ($pos->price_mode === 'manuell') {
            return ['vk' => $pos->price_value !== null ? (float) $pos->price_value : null, 'quelle' => 'manuell'];
        }

        if ($pos->type === 'gericht_ref') {
            // Expliziter Darreichungs-Override (Glas/Flasche/Portion) hat Vorrang.
            if ($pos->presentation_id) {
                $darr = FoodAlchemistRecipeDarreichung::find($pos->presentation_id);
                if ($darr?->sales_net !== null) {
                    return ['vk' => (float) $darr->sales_net, 'quelle' => 'darreichung'];
                }
            }
            $dish = $pos->relationLoaded('dish') ? $pos->dish : $pos->dish()->first();
            if ($dish) {
                return $this->darreichung->vkNettoMitQuelle($dish);
            }
        }

        if ($pos->type === 'menue_ref') {
            $concept = $pos->relationLoaded('concept') ? $pos->concept : $pos->concept()->first();
            if ($concept) {
                $cockpit = $this->concepts->preisCockpit($concept);

                return ['vk' => (float) $cockpit['price_per_person'], 'quelle' => 'concept'];
            }
        }

        return ['vk' => null, 'quelle' => 'keine'];
    }

    // ── Picker-Kandidaten ────────────────────────────────────────────────────

    /** Einzelne Gerichte/Getränke (VK-Rezepte) für den gericht_ref-Picker. */
    public function gerichtKandidaten(Team $team, string $suche, int $limit = 20, ?int $hauptgruppe = null, ?int $dishClassId = null): Collection
    {
        return FoodAlchemistRecipe::visibleToTeam($team)->verkauf()
            ->whereNull('variant_source_recipe_id')
            ->when($suche !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            ->when($hauptgruppe !== null, fn ($q) => $q->where('dish_main_group_id', $hauptgruppe))
            ->when($dishClassId !== null, fn ($q) => $q->where('dish_class_id', $dishClassId))
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'sales_net']);
    }

    /** Concepts (Fix-Menüs) für den menue_ref-Picker. */
    public function conceptKandidaten(Team $team, string $suche, int $limit = 20): Collection
    {
        return FoodAlchemistConcept::visibleToTeam($team)->echte()
            ->when($suche !== '', fn ($q) => \Platform\FoodAlchemist\Support\Suche::like($q, 'name', $suche))
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'price_per_person_cache']);
    }

    // ── Guards ───────────────────────────────────────────────────────────────

    private function ownedRubrik(Team $team, int $id): FoodAlchemistSpeisekarteRubrik
    {
        $rubrik = FoodAlchemistSpeisekarteRubrik::visibleToTeam($team)->findOrFail($id);
        if (! $rubrik->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbte Speisekarte — Pflege nur durchs Besitzer-Team (D1).');
        }

        return $rubrik;
    }

    private function ownedPosition(Team $team, int $id): FoodAlchemistSpeisekartePosition
    {
        $pos = FoodAlchemistSpeisekartePosition::visibleToTeam($team)->findOrFail($id);
        if (! $pos->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbte Speisekarte — Pflege nur durchs Besitzer-Team (D1).');
        }

        return $pos;
    }

    private function guard(FoodAlchemistSpeisekarte $karte, Team $team): void
    {
        if (! $karte->isOwnedBy($team)) {
            throw new \RuntimeException('Geerbte Speisekarte — Pflege nur durchs Besitzer-Team (D1).');
        }
    }

    /** Prüft, ob $kandidatId ein Nachfahre von $rubrikId ist (Zyklus-Schutz beim Verschieben). */
    private function istNachfahre(int $karteId, int $rubrikId, int $kandidatId): bool
    {
        $kinder = FoodAlchemistSpeisekarteRubrik::where('menu_card_id', $karteId)
            ->where('parent_id', $rubrikId)->pluck('id')->all();
        foreach ($kinder as $kind) {
            if ((int) $kind === $kandidatId || $this->istNachfahre($karteId, (int) $kind, $kandidatId)) {
                return true;
            }
        }

        return false;
    }
}
