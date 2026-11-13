<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Platform\FoodAlchemist\Services\ConceptVorgabenService;

/**
 * Concepter-Editor, Reiter Aufbau: Gericht-Vorgabe an einer leeren Position (Dominique 13.11.2026).
 * Die Vorgabe sagt der Erstellung, WELCHES Gericht in die Position gehört; ohne sie erfindet die KI frei.
 * Geschrieben wird sofort beim Verlassen des Felds, über {@see ConceptVorgabenService::setze} (Eigentum, nur leere Position).
 *
 * Eigener Trait, weil Concepter\Editor auf seiner Größen-Basis steht (Spec 85 §9).
 */
trait ManagesSlotVorgabe
{
    /** $feld: titel | notiz. Leerer Titel löscht die Vorgabe samt Notiz. */
    public function vorgabeSpeichern(int $slotId, string $feld, string $wert): void
    {
        if ($this->type !== 'concepts' || $this->id === null || ! in_array($feld, ['titel', 'notiz'], true)) {
            return;
        }
        $slot = \Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot::visibleToTeam($this->team())->where('concept_id', $this->id)->find($slotId);
        if ($slot === null) {
            return;   // Position gehört nicht zu diesem Concept
        }
        try {
            app(ConceptVorgabenService::class)->setze(
                $this->team(),
                $slotId,
                $feld === 'titel' ? $wert : (string) $slot->dish_brief,
                $feld === 'notiz' ? $wert : (string) $slot->dish_brief_note,
            );
        } catch (\RuntimeException $e) {
            $this->dispatch('fa-saved', message: $e->getMessage(), type: 'error');

            return;
        }
        $this->dispatch('concepter-gespeichert', id: $this->id);
    }
}
