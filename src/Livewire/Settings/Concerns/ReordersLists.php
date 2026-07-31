<?php

namespace Platform\FoodAlchemist\Livewire\Settings\Concerns;

/**
 * Kleine Array-Helfer für die Pfeil-/Drag-and-Drop-Sortierung der Settings-Listen.
 * Arbeiten rein auf einer ID-Reihenfolge; das Persistieren (sort_order) macht der
 * VocabularyService. Gibt jeweils die neue Reihenfolge zurück, oder null wenn nichts
 * zu tun ist (Grenze erreicht / ID nicht gefunden).
 */
trait ReordersLists
{
    /** Nachbar-Tausch: verschiebt $id um eine Position ($richtung = -1 hoch, +1 runter). */
    private function reorderNachbar(array $ids, int $id, int $richtung): ?array
    {
        $pos = array_search($id, $ids, true);
        if ($pos === false) {
            return null;
        }
        $ziel = $pos + $richtung;
        if ($ziel < 0 || $ziel >= count($ids)) {
            return null;
        }
        [$ids[$pos], $ids[$ziel]] = [$ids[$ziel], $ids[$pos]];

        return $ids;
    }

    /** Drag-and-Drop: sortiert $id direkt hinter $afterId ein. */
    private function reorderHinter(array $ids, int $id, int $afterId): ?array
    {
        if ($id === $afterId
            || array_search($id, $ids, true) === false
            || array_search($afterId, $ids, true) === false) {
            return null;
        }
        array_splice($ids, array_search($id, $ids, true), 1);
        array_splice($ids, array_search($afterId, $ids, true) + 1, 0, [$id]);

        return $ids;
    }
}
