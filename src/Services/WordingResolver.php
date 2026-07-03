<?php

namespace Platform\FoodAlchemist\Services;

use Platform\FoodAlchemist\Models\FoodAlchemistConcept;
use Platform\FoodAlchemist\Models\FoodAlchemistConceptSlot;
use Platform\FoodAlchemist\Models\FoodAlchemistFoodbookBlock;
use Platform\FoodAlchemist\Models\FoodAlchemistRecipe;

/**
 * Wording-Kette (UX-Umbau 2026-07-03) — EINE Auflösungslogik für alle
 * kundensichtbaren Anzeigenamen (Concepter-Menü-Ansicht, Foodbook-Editor,
 * Foodbook-Kundendokument):
 *
 *     Foodbook-Block-Override → Concept-Slot.wording → Gericht.vk_wording_standard → Gericht.name
 *
 * Jede Stufe liefert neben dem Text die Quelle mit ('foodbook'|'konzept'|
 * 'standard'|'name'), damit die UIs zeigen können, woher ein Name kommt —
 * 'name' heißt: es würde der INTERNE Pipe-Name drucken → Handlungsbedarf.
 *
 * Per-Gericht-Override im Foodbook liegt in block.payload_json['wording_overrides']
 * (Map slot_id → Text) — Foodbooks komponieren Concepts (BLOCK_TYPES), daher
 * kein eigenes Schema pro Gericht-Zeile nötig. Verwaiste slot_ids (Concept
 * später umgebaut) matchen einfach nicht mehr und sind harmlos.
 */
class WordingResolver
{
    /** @return array{text: string, quelle: string} */
    public function fuerGericht(?FoodAlchemistRecipe $gericht): array
    {
        if ($gericht === null) {
            return ['text' => '—', 'quelle' => 'name'];
        }
        $std = trim((string) $gericht->vk_wording_standard);

        return $std !== ''
            ? ['text' => $std, 'quelle' => 'standard']
            : ['text' => (string) $gericht->name, 'quelle' => 'name'];
    }

    /** @return array{text: string, quelle: string} */
    public function fuerSlot(FoodAlchemistConceptSlot $slot): array
    {
        $w = trim((string) $slot->wording);
        if ($w !== '') {
            return ['text' => $w, 'quelle' => 'konzept'];
        }

        return $this->fuerGericht($slot->gericht);
    }

    /**
     * Gericht-Zeile INNERHALB eines Foodbook-Blocks (concept_ref):
     * payload_json['wording_overrides'][slot_id] → Slot-Kette.
     *
     * @return array{text: string, quelle: string}
     */
    public function fuerBlockSlot(FoodAlchemistFoodbookBlock $block, FoodAlchemistConceptSlot $slot): array
    {
        $override = trim((string) (($block->payload_json['wording_overrides'] ?? [])[(string) $slot->id]
            ?? ($block->payload_json['wording_overrides'] ?? [])[$slot->id] ?? ''));
        if ($override !== '') {
            return ['text' => $override, 'quelle' => 'foodbook'];
        }

        return $this->fuerSlot($slot);
    }

    /**
     * Titel eines Foodbook-Blocks (concept_ref/recipe_ref):
     * block.wording → (Legacy) block.kundentext → Concept-/Gericht-Kette.
     * kundentext bleibt als Fallback, weil Bestandsdaten ihn als Label nutzen —
     * neue Pflege schreibt `wording`, kundentext ist wieder Beschreibungstext.
     *
     * @return array{text: string, quelle: string}
     */
    public function blockTitel(FoodAlchemistFoodbookBlock $block): array
    {
        $w = trim((string) $block->wording);
        if ($w !== '') {
            return ['text' => $w, 'quelle' => 'foodbook'];
        }
        $legacy = trim((string) $block->kundentext);
        if ($legacy !== '' && $block->wording === null) {
            return ['text' => $legacy, 'quelle' => 'foodbook'];
        }

        return match ($block->type) {
            'concept_ref' => ['text' => (string) ($block->concept?->name ?? '—'), 'quelle' => 'name'],
            'recipe_ref' => $this->fuerGericht($block->gericht),
            default => ['text' => (string) ($block->bezeichnung ?? '—'), 'quelle' => 'name'],
        };
    }

    /**
     * Kundensichtbare Gericht-Zeilen eines Concepts, in Slot-Reihenfolge —
     * Gericht-Slots als Zeilen, Paket-Slots als Gruppe (Paketname + Gerichte).
     * Struktur-Slots (header/text/spacer) liefern Header als Zwischenzeile.
     *
     * @return list<array{typ: string, text: string, quelle: ?string, einrueckung: int}>
     */
    public function gerichtZeilen(FoodAlchemistConcept $concept, ?FoodAlchemistFoodbookBlock $block = null): array
    {
        $zeilen = [];
        foreach ($concept->slots->sortBy('position') as $slot) {
            if ($slot->paket_id !== null && $slot->paket !== null) {
                $zeilen[] = ['typ' => 'paket', 'text' => (string) $slot->paket->name, 'quelle' => null, 'einrueckung' => 0];
                foreach ($slot->paket->gerichte as $pg) {
                    $r = $this->fuerGericht($pg->gericht);
                    $zeilen[] = ['typ' => 'gericht', 'text' => $r['text'], 'quelle' => $r['quelle'], 'einrueckung' => 1];
                }

                continue;
            }
            if (in_array($slot->type, ['header', 'header_preis'], true) && trim((string) $slot->titel) !== '') {
                $zeilen[] = ['typ' => 'header', 'text' => (string) $slot->titel, 'quelle' => null, 'einrueckung' => 0];

                continue;
            }
            if ($slot->vk_recipe_id === null || $slot->gericht === null) {
                continue; // spacer/text/leere Slots sind im Kundendokument unsichtbar
            }
            $r = $block !== null ? $this->fuerBlockSlot($block, $slot) : $this->fuerSlot($slot);
            $zeilen[] = ['typ' => 'gericht', 'text' => $r['text'], 'quelle' => $r['quelle'], 'einrueckung' => 0, 'slot_id' => $slot->id];
        }

        return $zeilen;
    }
}
