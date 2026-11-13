<?php

namespace Platform\FoodAlchemist\Livewire\Concerns;

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Services\ConceptService;
use Platform\FoodAlchemist\Services\ConceptVorgabenService;

/**
 * Leitstelle, Plan-Panel „Menü-Aufbau": Gericht-Vorgaben je Position (Dominique 13.11.2026).
 * Der Plan zeigte bisher nur Rolle und Titel der Positionen. Hier lässt sich je leerer Position vorgeben,
 * welches Gericht entstehen soll, und eine weitere Position mit Vorgabe anlegen — ohne Wegsprung in den Concepter.
 * Geschrieben wird über {@see ConceptVorgabenService::setze}; der Plan ist das Concept hinter `$planConceptId`.
 *
 * Eigener Trait, weil Planung\Index auf seiner Größen-Basis steht (Spec 85 §5).
 */
trait PlanGerichtVorgaben
{
    /** Neue Position mit Vorgabe (Eingabefeld unter der Liste). */
    public string $planNeueVorgabe = '';

    /**
     * Positionen des Plans für das Panel: Gericht-Positionen in Reihenfolge, Struktur-Blöcke bleiben draußen.
     *
     * @return list<array{id:int, rolle:string, titel:string, pflicht:bool, leer:bool, gericht:?string, vorgabe:string, notiz:string}>
     */
    public function planPositionen(): array
    {
        $concept = $this->planConcept();
        if ($concept === null) {
            return [];
        }

        return FoodAlchemistConceptSlot::where('team_id', $concept->team_id)->where('concept_id', $concept->id)
            ->whereNotIn('type', ConceptService::STRUKTUR_TYPEN)
            ->with(['gericht:id,name', 'embeddedConcept:id,name'])
            ->orderBy('position')->orderBy('id')->get()
            ->map(fn (FoodAlchemistConceptSlot $s) => [
                'id' => (int) $s->id,
                'rolle' => (string) ($s->role ?? ''),
                'titel' => (string) ($s->title ?? ''),
                'pflicht' => (bool) $s->is_pflicht,
                'leer' => ConceptVorgabenService::istLeer($s),
                'gericht' => $s->gericht?->name ?? $s->embeddedConcept?->name,
                'vorgabe' => (string) ($s->dish_brief ?? ''),
                'notiz' => (string) ($s->dish_brief_note ?? ''),
            ])->values()->all();
    }

    /** Vorgabe einer Position setzen, ändern oder (leer) löschen. */
    public function planVorgabeSpeichern(int $slotId, string $wert): void
    {
        $concept = $this->planConcept();
        if ($concept === null || ! FoodAlchemistConceptSlot::where('team_id', $concept->team_id)->where('concept_id', $concept->id)->whereKey($slotId)->exists()) {
            return;   // Position gehört nicht zum Plan
        }
        try {
            app(ConceptVorgabenService::class)->setze($this->team(), $slotId, $wert);
            $this->fehler = null;
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** Weitere Position mit Vorgabe an den Plan hängen. */
    public function planPositionMitVorgabe(): void
    {
        $concept = $this->planConcept();
        $vorgabe = trim($this->planNeueVorgabe);
        if ($concept === null || $vorgabe === '') {
            return;
        }
        try {
            $slot = app(ConceptService::class)->addSlot($this->team(), (int) $concept->id, []);
            app(ConceptVorgabenService::class)->setze($this->team(), (int) $slot->id, $vorgabe);
            $this->planNeueVorgabe = '';
            $this->fehler = null;
        } catch (\RuntimeException $e) {
            $this->fehler = $e->getMessage();
        }
    }

    /** Das team-eigene Concept hinter dem Plan, sonst null. */
    private function planConcept(): ?FoodAlchemistConcept
    {
        $team = $this->team();
        if ($team === null || (int) ($this->planConceptId ?? 0) <= 0) {
            return null;
        }

        return FoodAlchemistConcept::where('team_id', $team->id)->find((int) $this->planConceptId);
    }
}
