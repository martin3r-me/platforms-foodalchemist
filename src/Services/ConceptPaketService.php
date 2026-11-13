<?php

namespace Platform\FoodAlchemist\Services;

use Platform\Core\Models\Team;
use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Concepter-Pakete (kind=paket-Concepts) für den MCP-Pfad.
 *
 * Seit der Kaskade (2026-08-24) zeigt der Concepter-Reiter „Pakete" ausschließlich
 * kind=paket-Concepts. Die MCP-Tools `foodalchemist.pakete.*` / `paket_gerichte.*` liefen bis
 * hierher noch auf dem Alt-Modell (foodalchemist_packages) und erzeugten so unsichtbare Pakete.
 * Dieser Service bündelt die Paket-Sicht auf das Concept-Modell; alle Schreibwege laufen über
 * {@see ConceptService}, damit Preis-Cache, Ownership und Invarianten identisch bleiben.
 */
class ConceptPaketService
{
    public function __construct(private ConceptService $concepts) {}

    /** Sichtbares Paket (Team-Ancestry + global) oder null. */
    public function sichtbar(Team $team, int $id): ?FoodAlchemistConcept
    {
        return FoodAlchemistConcept::visibleToTeam($team)->pakete()->whereKey($id)->first();
    }

    /**
     * Neues Concepter-Paket. MCP legt immer als Entwurf an (Aktivieren bleibt menschlich),
     * damit es danach per paket_gerichte.SET / concept_slots.POST befüllbar ist.
     */
    public function create(Team $team, array $in): FoodAlchemistConcept
    {
        $paket = $this->concepts->createPaket($team, [
            'name' => $in['name'] ?? null,
            'class' => $in['class'] ?? null,
            'level' => $in['level'] ?? null,
            'status' => 'draft',
        ]);
        $extras = $this->felder($in);
        if ($extras !== []) {
            $paket = $this->concepts->update($team, $paket->id, $extras);
        }

        return $paket->refresh();
    }

    /** Stammfelder ändern (gleiche Whitelist wie concepts.PUT, Paket-Vokabular gemappt). */
    public function update(Team $team, int $id, array $in): FoodAlchemistConcept
    {
        return $this->concepts->update($team, $id, $this->felder($in));
    }

    /**
     * Gericht-Positionen als Vollersatz setzen. Bestehende Positionen (gleiches Gericht) behalten
     * Menge/Einheit/Darreichung, solange das Item das Feld nicht mitbringt. Struktur-Slots
     * (Überschrift/Text) bleiben erhalten und rücken hinter die Gerichte.
     *
     * @param  list<array{sales_recipe_id:int, quantity?:?float, unit_vocab_id?:?int, presentation_id?:?int}>  $items
     */
    public function syncGerichte(Team $team, int $paketId, array $items): FoodAlchemistConcept
    {
        $paket = $this->sichtbar($team, $paketId) ?? throw new \RuntimeException('Paket nicht sichtbar/vorhanden.');
        $slots = $paket->slots()->orderBy('position')->get();
        $gerichtSlots = $slots->filter(fn ($s) => in_array($s->type, ['gericht', 'basisrezept'], true) && $s->sales_recipe_id !== null);
        $frei = $gerichtSlots->groupBy(fn ($s) => (int) $s->sales_recipe_id)->map->values();
        $istBasis = FoodAlchemistRecipe::whereIn('id', array_map(fn ($i) => (int) $i['sales_recipe_id'], $items))
            ->pluck('is_sales_recipe', 'id');

        $reihenfolge = [];
        foreach ($items as $item) {
            $rid = (int) $item['sales_recipe_id'];
            $slot = ($frei[$rid] ?? collect())->shift();
            if ($slot === null) {
                $slot = $this->concepts->addSlot($team, $paket->id, []);
                $slot = $this->concepts->fillSlot($team, $slot->id, [
                    'sales_recipe_id' => $rid,
                    'type' => ($istBasis[$rid] ?? true) ? 'gericht' : 'basisrezept',
                    'quantity' => $item['quantity'] ?? null,
                    'unit_vocab_id' => $item['unit_vocab_id'] ?? null,
                ]);
            } elseif (array_key_exists('quantity', $item) || array_key_exists('unit_vocab_id', $item)) {
                $slot = $this->concepts->setSlotMengeEinheit(
                    $team, $slot->id,
                    array_key_exists('quantity', $item) ? $item['quantity'] : ($slot->quantity !== null ? (float) $slot->quantity : null),
                    array_key_exists('unit_vocab_id', $item) ? $item['unit_vocab_id'] : $slot->unit_vocab_id,
                );
            }
            if (array_key_exists('presentation_id', $item)) {
                $slot = $this->concepts->setSlotDarreichung($team, $slot->id, $item['presentation_id'] ?: null);
            }
            $reihenfolge[] = (int) $slot->id;
        }
        // Nicht mehr gelistete Gericht-Positionen entfernen.
        foreach ($frei->flatten(1) as $rest) {
            $this->concepts->removeSlot($team, (int) $rest->id);
        }
        $struktur = $slots->reject(fn ($s) => in_array($s->type, ['gericht', 'basisrezept'], true) && $s->sales_recipe_id !== null)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->concepts->reorderSlots($team, $paket->id, array_merge($reihenfolge, $struktur));

        return $this->concepts->recomputeCache($paket->refresh());
    }

    /** Menge/Person einer Gericht-Position (row_id = concept_slot.id) setzen. */
    public function setMenge(Team $team, int $paketId, int $rowId, ?float $quantity): FoodAlchemistConceptSlot
    {
        $this->pruefeSlot($paketId, $rowId);
        $slot = FoodAlchemistConceptSlot::findOrFail($rowId);

        return $this->concepts->setSlotMengeEinheit($team, $rowId, $quantity, $slot->unit_vocab_id);
    }

    /** @param list<int> $rowIds Gericht-Positionen in neuer Reihenfolge (übrige Slots rücken dahinter). */
    public function reorder(Team $team, int $paketId, array $rowIds): void
    {
        $alle = FoodAlchemistConceptSlot::where('concept_id', $paketId)->orderBy('position')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $rowIds = array_values(array_intersect(array_map('intval', $rowIds), $alle));
        $this->concepts->reorderSlots($team, $paketId, array_merge($rowIds, array_values(array_diff($alle, $rowIds))));
    }

    /** MCP-Antwort für ein Concepter-Paket (Schlüssel kompatibel zur alten pakete.*-Antwort). */
    public function payload(FoodAlchemistConcept $p, bool $mitGerichten = false): array
    {
        $vk = $p->price_per_person_cache !== null ? (float) $p->price_per_person_cache : null;
        $ek = $p->ek_per_person_cache !== null ? (float) $p->ek_per_person_cache : null;
        $out = [
            'id' => (int) $p->id,
            'kind' => 'paket',
            'name' => $p->name,
            'consumer_name' => $p->consumer_name,
            'class' => $p->class,
            'level' => $p->level,
            'status' => $p->status,
            'price_mode' => $p->price_mode,
            'price_per_person' => $vk,
            'ek_per_person' => $ek,
            'food_cost_percent' => ($vk !== null && $ek !== null && $vk > 0) ? round($ek / $vk * 100, 1) : null,
            'is_inactive' => $p->status === 'archiviert',
        ];
        if ($mitGerichten) {
            $p->loadMissing(['slots' => fn ($q) => $q->orderBy('position'), 'slots.dish:id,name', 'slots.unit:id,slug', 'slots.presentation.servingForm']);
            $out['dishes'] = $p->slots
                ->filter(fn ($s) => $s->sales_recipe_id !== null)
                ->map(fn ($s) => [
                    'row_id' => (int) $s->id,
                    'sales_recipe_id' => (int) $s->sales_recipe_id,
                    'name' => $s->dish?->name,
                    'quantity' => $s->quantity !== null ? (float) $s->quantity : null,
                    'unit' => $s->unit?->slug,
                    'presentation_id' => $s->presentation_id !== null ? (int) $s->presentation_id : null,
                    'darreichung' => $s->presentation_id !== null ? $s->presentation?->servingForm?->label : null,
                ])->values()->all();
        }

        return $out;
    }

    /** Paket-Vokabular → Concept-Felder (price_per_person → price_per_person_manual). */
    private function felder(array $in): array
    {
        $out = array_intersect_key($in, array_flip([
            'name', 'consumer_name', 'class', 'level', 'price_mode', 'price_override_reason', 'description', 'note',
        ]));
        if (array_key_exists('price_per_person', $in)) {
            $out['price_per_person_manual'] = $in['price_per_person'];
        }

        return $out;   // price_mode=fixed ohne Preis/Begründung lehnt ConceptService::update ab.
    }

    private function pruefeSlot(int $paketId, int $rowId): void
    {
        if (! FoodAlchemistConceptSlot::whereKey($rowId)->where('concept_id', $paketId)->exists()) {
            throw new \RuntimeException("Position {$rowId} gehört nicht zu Paket {$paketId}.");
        }
    }
}
